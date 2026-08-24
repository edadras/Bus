import 'package:dio/dio.dart';

import '../models/models.dart';
import 'api_client.dart';
import 'api_exception.dart';

/// Typed calls onto the platform API.
///
/// Grouped by the surface they belong to so each app depends only on the part
/// it is authorised to use — a passenger build never even names the driver
/// endpoints.
class TransitApi {
  TransitApi(this._client);

  final ApiClient _client;

  ApiClient get client => _client;

  // ── Authentication ──────────────────────────────────────────────────────

  /// Request a login code. Returns the debug code in non-production builds so
  /// the app is testable without an SMS gateway.
  Future<String?> requestOtp(String mobile) async {
    final result = await _client.post('/auth/otp/request', body: {'mobile': mobile});

    return result.asMap['debug_code'] as String?;
  }

  Future<({String token, List<String> abilities, AppUser user})> verifyOtp({
    required String mobile,
    required String code,
    required String client,
    String? deviceName,
  }) async {
    final result = await _client.post(
      '/auth/otp/verify',
      body: {
        'mobile': mobile,
        'code': code,
        'client': client,
        if (deviceName != null) 'device_name': deviceName,
      },
    );

    final data = result.asMap;

    return (
      token: data['token'] as String,
      abilities: (data['abilities'] as List<dynamic>).cast<String>(),
      user: AppUser.fromJson(data['user'] as Map<String, dynamic>),
    );
  }

  Future<AppUser> me() async {
    final result = await _client.get('/auth/me');

    return AppUser.fromJson(result.asMap['user'] as Map<String, dynamic>);
  }

  Future<void> logout() => _client.post('/auth/logout');

  // ── Public network ──────────────────────────────────────────────────────

  Future<MapConfig> mapConfig() async {
    final result = await _client.get('/map/config');

    return MapConfig.fromJson(result.asMap);
  }

  Future<List<BusStop>> stops({
    double? lat,
    double? lng,
    int? radius,
    String? query,
    int limit = 30,
  }) async {
    final result = await _client.get(
      '/stops',
      query: {
        'lat': lat,
        'lng': lng,
        'radius': radius,
        'q': query,
        'limit': limit,
      },
    );

    return result.asList.map(BusStop.fromJson).toList();
  }

  Future<List<BusLine>> lines({String? query}) async {
    final result = await _client.get('/lines', query: {'q': query});

    return result.asList.map(BusLine.fromJson).toList();
  }

  /// One line with the routes it runs. The stop sequence lives on the route,
  /// not the line — a line usually has an outward and a return that are not
  /// mirror images of each other.
  Future<LineDetail> line(int id) async {
    final result = await _client.get('/lines/$id');

    return LineDetail.fromJson(result.asMap);
  }

  /// One route, with its stops in order.
  Future<RouteDetail> route(int id) async {
    final result = await _client.get('/routes/$id');

    return RouteDetail.fromJson(result.asMap);
  }

  /// When this particular bus reaches this particular stop.
  ///
  /// Null when the server has no estimate to give — the bus has already passed
  /// the stop, or the trip is not live. That is an answer, not a failure, so it
  /// is returned rather than thrown.
  Future<StopEta?> tripEta(String tripUuid, int stopId) async {
    try {
      final result = await _client.get('/trips/$tripUuid/eta/$stopId');

      return StopEta.fromJson(result.asMap);
    } on ApiException catch (error) {
      if (error.code == 'eta_unavailable') return null;

      rethrow;
    }
  }

  Future<List<Arrival>> arrivals(int stopId, {int? lineId, int limit = 10}) async {
    final result = await _client.get(
      '/stops/$stopId/arrivals',
      query: {'line_id': lineId, 'limit': limit},
    );

    return result.asList.map(Arrival.fromJson).toList();
  }

  Future<List<LiveBus>> liveBuses({int? lineId, double? lat, double? lng, int? radius}) async {
    final result = await _client.get(
      '/buses/live',
      query: {
        'line_id': lineId,
        'lat': lat,
        'lng': lng,
        'radius': radius,
      },
    );

    return result.asList.map(LiveBus.fromJson).toList();
  }

  Future<Map<String, dynamic>> summary() async {
    final result = await _client.get('/summary');

    return result.asMap;
  }

  Future<JourneyPlan> planJourney({
    required double fromLat,
    required double fromLng,
    required double toLat,
    required double toLng,
    int? maxTransfers,
  }) async {
    final result = await _client.get(
      '/journey/plan',
      query: {
        'from_lat': fromLat,
        'from_lng': fromLng,
        'to_lat': toLat,
        'to_lng': toLng,
        'max_transfers': maxTransfers,
      },
    );

    return JourneyPlan.fromJson(result.asMap);
  }

  // ── Passenger ───────────────────────────────────────────────────────────

  Future<Wallet> wallet() async {
    final result = await _client.get('/wallet');

    return Wallet.fromJson(result.asMap['wallet'] as Map<String, dynamic>);
  }

  Future<List<LedgerEntry>> walletTransactions({int page = 1}) async {
    final result = await _client.get('/wallet/transactions', query: {'page': page});

    return result.asList.map(LedgerEntry.fromJson).toList();
  }

  /// Start a top-up. The wallet is credited only after the gateway callback is
  /// verified server-side, never on the redirect alone.
  Future<({String paymentUuid, String redirectUrl})> topup(int amount) async {
    final result = await _client.post('/wallet/topup', body: {'amount': amount});
    final data = result.asMap;

    return (
      paymentUuid: (data['payment'] as Map<String, dynamic>)['uuid'] as String,
      redirectUrl: (data['redirect'] as Map<String, dynamic>)['url'] as String,
    );
  }

  Future<Map<String, dynamic>> paymentStatus(String uuid) async {
    final result = await _client.get('/wallet/payments/$uuid');

    return result.asMap;
  }

  /// Board a bus by presenting a scanned, signed QR token.
  Future<Map<String, dynamic>> board({
    required String token,
    double? lat,
    double? lng,
    String? deviceId,
  }) async {
    final result = await _client.post(
      '/bus/scan',
      body: {
        'token': token,
        if (lat != null && lng != null) 'lat': lat,
        if (lat != null && lng != null) 'lng': lng,
        if (deviceId != null) 'device_id': deviceId,
      },
    );

    return result.asMap;
  }

  Future<ActiveRide?> activeRide() async {
    final result = await _client.get('/rides/active');

    return result.data == null ? null : ActiveRide.fromJson(result.asMap);
  }

  /// Opt-in location sample used only to detect that the passenger has got off.
  Future<Map<String, dynamic>> reportRidePosition({
    required String rideUuid,
    required double lat,
    required double lng,
    double? accuracy,
  }) async {
    final result = await _client.post(
      '/rides/$rideUuid/ping',
      body: {
        'lat': lat,
        'lng': lng,
        if (accuracy != null) 'accuracy': accuracy,
      },
    );

    return result.asMap;
  }

  Future<void> endRide(String rideUuid, {double? lat, double? lng}) => _client.post(
        '/rides/$rideUuid/end',
        body: {
          if (lat != null) 'lat': lat,
          if (lng != null) 'lng': lng,
        },
      );

  Future<List<RideHistoryItem>> rideHistory({int page = 1}) async {
    final result = await _client.get('/rides', query: {'page': page});

    return result.asList.map(RideHistoryItem.fromJson).toList();
  }

  Future<Map<String, dynamic>> fareQuote(int lineId) async {
    final result = await _client.get('/fare/quote', query: {'line_id': lineId});

    return result.asMap;
  }

  // ── Complaints ──────────────────────────────────────────────────────────

  Future<List<({String value, String label})>> complaintCategories() async {
    final result = await _client.get('/complaints/categories');

    return result.asList
        .map((item) => (value: item['value'] as String, label: item['label'] as String))
        .toList();
  }

  Future<List<Complaint>> complaints() async {
    final result = await _client.get('/complaints');

    return result.asList.map(Complaint.fromJson).toList();
  }

  Future<Complaint> complaint(String uuid) async {
    final result = await _client.get('/complaints/$uuid');

    return Complaint.fromJson(result.asMap);
  }

  Future<Complaint> submitComplaint({
    required String category,
    required String subject,
    required String body,
    List<MultipartFile> attachments = const [],
    double? lat,
    double? lng,
  }) async {
    final form = FormData.fromMap({
      'category': category,
      'subject': subject,
      'body': body,
      if (lat != null) 'lat': lat,
      if (lng != null) 'lng': lng,
      if (attachments.isNotEmpty) 'attachments[]': attachments,
    });

    final result = await _client.upload('/complaints', form);

    return Complaint.fromJson(result.asMap);
  }

  Future<Complaint> replyToComplaint(String uuid, String body) async {
    final result = await _client.post('/complaints/$uuid/reply', body: {'body': body});

    return Complaint.fromJson(result.asMap);
  }

  /// Say whether a resolved complaint was actually resolved, one to five.
  Future<Complaint> rateComplaint(String uuid, int rating) async {
    final result = await _client.post('/complaints/$uuid/rate', body: {'rating': rating});

    return Complaint.fromJson(result.asMap);
  }

  // ── Notifications ───────────────────────────────────────────────────────

  /// The in-app inbox. Every notification the platform sends lands here, so
  /// this list is complete even for a device that never granted push.
  Future<({List<AppNotification> items, int unreadCount})> notifications({
    bool unreadOnly = false,
  }) async {
    final result = await _client.get(
      '/notifications',
      query: {if (unreadOnly) 'unread': '1'},
    );

    return (
      items: result.asList.map(AppNotification.fromJson).toList(),
      unreadCount: (result.meta?['unread_count'] as num?)?.toInt() ?? 0,
    );
  }

  Future<int> unreadNotificationCount() async {
    final result = await _client.get('/notifications/unread-count');

    return (result.asMap['unread_count'] as num?)?.toInt() ?? 0;
  }

  Future<void> markNotificationRead(String id) => _client.post('/notifications/$id/read');

  Future<void> markAllNotificationsRead() => _client.post('/notifications/read-all');

  /// Whether this deployment can deliver a native push at all.
  ///
  /// Asked before prompting for notification permission: an accepted prompt
  /// that can never deliver anything is worse than no prompt, and a declined
  /// one is hard to ask for again.
  Future<bool> nativePushEnabled() async {
    final result = await _client.get('/push/key');

    return result.asMap['native_enabled'] == true;
  }

  /// Register this device for push.
  ///
  /// A native build passes the registration token it got from the OS and no
  /// keys; a browser passes its Web Push endpoint and key pair.
  Future<void> registerPushSubscription({
    required String endpoint,
    String? publicKey,
    String? authToken,
    String? platform,
    String? deviceName,
  }) =>
      _client.post(
        '/push/subscriptions',
        body: {
          'endpoint': endpoint,
          if (publicKey != null && authToken != null)
            'keys': {'p256dh': publicKey, 'auth': authToken},
          if (platform != null) 'platform': platform,
          if (deviceName != null) 'device_name': deviceName,
        },
      );

  // ── Driver ──────────────────────────────────────────────────────────────

  Future<Map<String, dynamic>> driverState() async {
    final result = await _client.get('/driver/state');

    return result.asMap;
  }

  Future<Map<String, dynamic>> startShift({
    required String token,
    double? lat,
    double? lng,
  }) async {
    final result = await _client.post(
      '/driver/shifts/start',
      body: {
        'token': token,
        if (lat != null) 'lat': lat,
        if (lng != null) 'lng': lng,
      },
    );

    return result.asMap;
  }

  Future<void> endShift({double? lat, double? lng}) => _client.post(
        '/driver/shifts/end',
        body: {if (lat != null) 'lat': lat, if (lng != null) 'lng': lng},
      );

  Future<Map<String, dynamic>> startTrip(int routeId) async {
    final result = await _client.post('/driver/trips/start', body: {'route_id': routeId});

    return result.asMap;
  }

  Future<void> pauseTrip() => _client.post('/driver/trips/pause');

  Future<void> resumeTrip() => _client.post('/driver/trips/resume');

  Future<void> completeTrip() => _client.post('/driver/trips/complete');

  /// Report a position. The response tells the app when to report next, so the
  /// server controls battery cost centrally rather than each app guessing.
  Future<Map<String, dynamic>> reportLocation({
    required double lat,
    required double lng,
    double? speedKmh,
    double? heading,
    double? accuracy,
    double? altitude,
    DateTime? recordedAt,
  }) async {
    final result = await _client.post(
      '/driver/location',
      body: {
        'lat': lat,
        'lng': lng,
        if (speedKmh != null) 'speed': speedKmh,
        if (heading != null) 'heading': heading,
        if (accuracy != null) 'accuracy': accuracy,
        if (altitude != null) 'altitude': altitude,
        if (recordedAt != null) 'recorded_at': recordedAt.toUtc().toIso8601String(),
      },
    );

    return result.asMap;
  }

  Future<Map<String, dynamic>> driverPassengers() async {
    final result = await _client.get('/driver/passengers');

    return result.asMap;
  }

  Future<Map<String, dynamic>> driverRoute() async {
    final result = await _client.get('/driver/route');

    return result.asMap;
  }

  // ── Taxi, from the passenger's side ─────────────────────────────────────

  /// Taxis near a point. The radius is capped by the server, and the payload
  /// deliberately carries no plate, driver or passenger count.
  Future<List<NearbyTaxi>> nearbyTaxis({
    required double lat,
    required double lng,
    int radius = 1500,
    String? serviceType,
  }) async {
    final result = await _client.get('/taxis/nearby', query: {
      'lat': lat,
      'lng': lng,
      'radius': radius,
      if (serviceType != null) 'service_type': serviceType,
    });

    return result.asList.map(NearbyTaxi.fromJson).toList();
  }

  Future<List<TaxiLine>> taxiLines({String? query}) async {
    final result = await _client.get('/taxi/lines', query: {'q': query});

    return result.asList.map(TaxiLine.fromJson).toList();
  }

  /// What this ride would cost. Commits to nothing — [takeTaxiRide] does.
  Future<TaxiScanResult> scanTaxi({
    required String token,
    double? lat,
    double? lng,
  }) async {
    final result = await _client.post('/taxi/scan', body: {
      'token': token,
      if (lat != null) 'lat': lat,
      if (lng != null) 'lng': lng,
    });

    return TaxiScanResult.fromJson(result.asMap);
  }

  /// Take the ride.
  ///
  /// [acceptedAmount] is the figure the passenger was actually shown. The
  /// server refuses anything else, which is what makes a price the passenger
  /// never saw impossible to charge.
  Future<TaxiRide> takeTaxiRide({
    required String token,
    int? acceptedAmount,
    double? lat,
    double? lng,
    String? deviceId,
  }) async {
    final result = await _client.post('/taxi/rides', body: {
      'token': token,
      if (acceptedAmount != null) 'accepted_amount': acceptedAmount,
      if (lat != null) 'lat': lat,
      if (lng != null) 'lng': lng,
      if (deviceId != null) 'device_id': deviceId,
    });

    return TaxiRide.fromJson(result.asMap);
  }

  Future<TaxiRide?> activeTaxiRide() async {
    final result = await _client.get('/taxi/rides/active');

    return result.data == null ? null : TaxiRide.fromJson(result.asMap);
  }

  Future<TaxiRide> endTaxiRide(String uuid, {double? lat, double? lng}) async {
    final result = await _client.post('/taxi/rides/$uuid/end', body: {
      if (lat != null) 'lat': lat,
      if (lng != null) 'lng': lng,
    });

    return TaxiRide.fromJson(result.asMap);
  }

  Future<({List<TaxiRide> items, int outstanding})> taxiRideHistory({int page = 1}) async {
    final result = await _client.get('/taxi/rides', query: {'page': page});

    return (
      items: result.asList.map(TaxiRide.fromJson).toList(),
      outstanding: (result.meta?['outstanding'] as num?)?.toInt() ?? 0,
    );
  }

  /// Pay off a metered ride the wallet could not cover when it ended.
  Future<TaxiRide> settleTaxiRide(String uuid) async {
    final result = await _client.post('/taxi/rides/$uuid/settle');

    return TaxiRide.fromJson(result.asMap);
  }

  // ── Taxi driver ─────────────────────────────────────────────────────────

  Future<Map<String, dynamic>> taxiDriverState() async {
    final result = await _client.get('/taxi/driver/state');

    return result.asMap;
  }

  Future<List<TaxiLine>> taxiDriverLines() async {
    final result = await _client.get('/taxi/driver/lines');

    return result.asList.map(TaxiLine.fromJson).toList();
  }

  Future<Map<String, dynamic>> startTaxiShift({
    required String taxiUuid,
    required String serviceType,
    int? taxiLineId,
    double? lat,
    double? lng,
  }) async {
    final result = await _client.post('/taxi/driver/shifts/start', body: {
      'taxi_uuid': taxiUuid,
      'service_type': serviceType,
      if (taxiLineId != null) 'taxi_line_id': taxiLineId,
      if (lat != null) 'lat': lat,
      if (lng != null) 'lng': lng,
    });

    return result.asMap;
  }

  Future<Map<String, dynamic>> endTaxiShift({double? lat, double? lng}) async {
    final result = await _client.post('/taxi/driver/shifts/end', body: {
      if (lat != null) 'lat': lat,
      if (lng != null) 'lng': lng,
    });

    return result.asMap;
  }

  /// Change what the car is offering, mid-shift.
  Future<Map<String, dynamic>> switchTaxiMode({
    required String serviceType,
    int? taxiLineId,
  }) async {
    final result = await _client.post('/taxi/driver/shifts/mode', body: {
      'service_type': serviceType,
      if (taxiLineId != null) 'taxi_line_id': taxiLineId,
    });

    return result.asMap;
  }

  /// Name the price for the charter in front of the driver.
  Future<Map<String, dynamic>> setCharterAmount(int amount) async {
    final result = await _client.post('/taxi/driver/charter', body: {'amount': amount});

    return result.asMap;
  }

  Future<void> clearCharterAmount() => _client.delete('/taxi/driver/charter');

  Future<Map<String, dynamic>> taxiDriverQr() async {
    final result = await _client.get('/taxi/driver/qr');

    return result.asMap;
  }

  /// A taxi's position report. One call moves the dot on the live map, stores
  /// the car's last position and, while a meter runs, advances the fare — the
  /// distance always comes from the car, never from a passenger's phone.
  Future<Map<String, dynamic>> reportTaxiLocation({
    required double lat,
    required double lng,
    double? speedKmh,
    double? accuracy,
    DateTime? recordedAt,
  }) async {
    final result = await _client.post('/taxi/driver/location', body: {
      'lat': lat,
      'lng': lng,
      if (speedKmh != null) 'speed': speedKmh,
      if (accuracy != null) 'accuracy': accuracy,
      if (recordedAt != null) 'recorded_at': recordedAt.toUtc().toIso8601String(),
    });

    return result.asMap;
  }

  Future<Map<String, dynamic>> taxiDriverRides() async {
    final result = await _client.get('/taxi/driver/rides');

    return result.asMap;
  }

  Future<TaxiRide> endTaxiRideAsDriver(String uuid, {double? lat, double? lng}) async {
    final result = await _client.post('/taxi/driver/rides/$uuid/end', body: {
      if (lat != null) 'lat': lat,
      if (lng != null) 'lng': lng,
    });

    return TaxiRide.fromJson(result.asMap);
  }

  Future<Map<String, dynamic>> taxiEarnings({String? from, String? to}) async {
    final result = await _client.get('/taxi/driver/earnings', query: {'from': from, 'to': to});

    return result.asMap;
  }

  Future<({List<TaxiSettlement> items, int pending})> taxiSettlements({int page = 1}) async {
    final result = await _client.get('/taxi/driver/settlements', query: {'page': page});

    return (
      items: result.asList.map(TaxiSettlement.fromJson).toList(),
      pending: (result.meta?['pending'] as num?)?.toInt() ?? 0,
    );
  }

  Future<TaxiSettlement> requestTaxiSettlement({String? from, String? to}) async {
    final result = await _client.post('/taxi/driver/settlements', body: {
      if (from != null) 'from': from,
      if (to != null) 'to': to,
    });

    return TaxiSettlement.fromJson(result.asMap);
  }

  // ── School service, from the family's side ──────────────────────────────

  Future<List<SchoolCompany>> schoolCompanies({String? query}) async {
    final result = await _client.get('/school/companies', query: {'q': query});

    return result.asList.map(SchoolCompany.fromJson).toList();
  }

  Future<List<SchoolSummary>> schoolsList({String? query}) async {
    final result = await _client.get('/school/schools', query: {'q': query});

    return result.asList.map(SchoolSummary.fromJson).toList();
  }

  Future<List<SchoolStudent>> schoolStudents() async {
    final result = await _client.get('/school/students');

    return result.asList.map(SchoolStudent.fromJson).toList();
  }

  Future<SchoolStudent> saveSchoolStudent(Map<String, dynamic> data, {String? uuid}) async {
    final result = uuid == null
        ? await _client.post('/school/students', body: data)
        : await _client.patch('/school/students/$uuid', body: data);

    return SchoolStudent.fromJson(result.asMap);
  }

  Future<List<SchoolContract>> schoolContracts() async {
    final result = await _client.get('/school/contracts');

    return result.asList.map(SchoolContract.fromJson).toList();
  }

  Future<SchoolContract> requestSchoolContract({
    required String studentUuid,
    required String companyUuid,
    String? direction,
    String? startsOn,
    List<int>? daysOfWeek,
    String? pickupAddress,
    double? pickupLat,
    double? pickupLng,
    String? note,
  }) async {
    final result = await _client.post('/school/contracts', body: {
      'student_uuid': studentUuid,
      'company_uuid': companyUuid,
      if (direction != null) 'direction': direction,
      if (startsOn != null) 'starts_on': startsOn,
      if (daysOfWeek != null) 'days_of_week': daysOfWeek,
      if (pickupAddress != null) 'pickup_address': pickupAddress,
      if (pickupLat != null) 'pickup_lat': pickupLat,
      if (pickupLng != null) 'pickup_lng': pickupLng,
      if (note != null) 'guardian_note': note,
    });

    return SchoolContract.fromJson(result.asMap);
  }

  Future<SchoolContract> endSchoolContract(String uuid, {String? reason}) async {
    final result = await _client.post('/school/contracts/$uuid/end', body: {
      if (reason != null) 'reason': reason,
    });

    return SchoolContract.fromJson(result.asMap);
  }

  Future<List<SchoolInvoice>> schoolInvoices({int page = 1}) async {
    final result = await _client.get('/school/invoices', query: {'page': page});

    return result.asList.map(SchoolInvoice.fromJson).toList();
  }

  Future<SchoolInvoice> paySchoolInvoice(String uuid) async {
    final result = await _client.post('/school/invoices/$uuid/pay');

    return SchoolInvoice.fromJson(result.asMap);
  }

  /// Where my child is right now.
  ///
  /// Null is a legitimate answer for most of the day, and the reason comes
  /// back in the metadata so the app can say "no run under way" rather than
  /// showing an error.
  Future<({SchoolLiveView? view, String? reason})> schoolStudentLive(String uuid) async {
    final result = await _client.get('/school/students/$uuid/live');

    return (
      view: result.data == null ? null : SchoolLiveView.fromJson(result.asMap),
      reason: result.meta?['reason'] as String?,
    );
  }

  Future<List<SchoolAttendanceRow>> schoolStudentAttendance(String uuid) async {
    final result = await _client.get('/school/students/$uuid/attendance');

    return result.asList.map(SchoolAttendanceRow.fromJson).toList();
  }

  Future<int> reportSchoolAbsence(String uuid, {String? note, String? direction}) async {
    final result = await _client.post('/school/students/$uuid/absence', body: {
      if (note != null) 'note': note,
      if (direction != null) 'direction': direction,
    });

    return (result.asMap['marked'] as num?)?.toInt() ?? 0;
  }

  // ── School driver ───────────────────────────────────────────────────────

  Future<Map<String, dynamic>> schoolDriverState({String? date}) async {
    final result = await _client.get('/school/driver/state', query: {'date': date});

    return result.asMap;
  }

  Future<SchoolTrip> schoolTrip(String uuid) async {
    final result = await _client.get('/school/driver/trips/$uuid');

    return SchoolTrip.fromJson(result.asMap);
  }

  Future<SchoolTrip> startSchoolTrip(String uuid, {double? lat, double? lng}) async {
    final result = await _client.post('/school/driver/trips/$uuid/start', body: {
      if (lat != null) 'lat': lat,
      if (lng != null) 'lng': lng,
    });

    return SchoolTrip.fromJson(result.asMap);
  }

  Future<SchoolTrip> completeSchoolTrip(String uuid, {double? lat, double? lng}) async {
    final result = await _client.post('/school/driver/trips/$uuid/complete', body: {
      if (lat != null) 'lat': lat,
      if (lng != null) 'lng': lng,
    });

    return SchoolTrip.fromJson(result.asMap);
  }

  /// Check a child on or off the van. [action] is `pickup`, `dropoff`,
  /// `absent` or `reset` — the last because a wrong tap at a kerb in the rain
  /// is a thing that happens.
  Future<SchoolTripStudent> checkSchoolStudent(
    String uuid,
    String action, {
    double? lat,
    double? lng,
    String? note,
  }) async {
    final result = await _client.post('/school/driver/students/$uuid/$action', body: {
      if (lat != null) 'lat': lat,
      if (lng != null) 'lng': lng,
      if (note != null) 'note': note,
    });

    return SchoolTripStudent.fromJson(result.asMap);
  }

  Future<Map<String, dynamic>> reportSchoolLocation({
    required double lat,
    required double lng,
    double? speedKmh,
  }) async {
    final result = await _client.post('/school/driver/location', body: {
      'lat': lat,
      'lng': lng,
      if (speedKmh != null) 'speed': speedKmh,
    });

    return result.asMap;
  }

  // ── Merchant ────────────────────────────────────────────────────────────

  Future<Map<String, dynamic>> merchantState() async {
    final result = await _client.get('/merchant/state');

    return result.asMap;
  }

  Future<Map<String, dynamic>> terminalToken(int terminalId) async {
    final result = await _client.get('/merchant/terminals/$terminalId/token');

    return result.asMap;
  }

  Future<List<Map<String, dynamic>>> merchantTransactions({int page = 1}) async {
    final result = await _client.get('/merchant/transactions', query: {'page': page});

    return result.asList;
  }

  Future<Map<String, dynamic>> refundMerchantTransaction(String uuid, String reason) async {
    final result = await _client.post(
      '/merchant/transactions/$uuid/refund',
      body: {'reason': reason},
    );

    return result.asMap;
  }

  Future<Map<String, dynamic>> merchantSalesReport({String? from, String? to}) async {
    final result = await _client.get('/merchant/reports/sales', query: {'from': from, 'to': to});

    return result.asMap;
  }

  Future<Map<String, dynamic>> requestSettlement({String? from, String? to}) async {
    final result = await _client.post(
      '/merchant/settlements/request',
      body: {if (from != null) 'from': from, if (to != null) 'to': to},
    );

    return result.asMap;
  }

  /// Passenger-side call: pay a merchant by scanning their till's QR.
  Future<Map<String, dynamic>> payMerchant({
    required String token,
    required int amount,
    String? description,
  }) async {
    final result = await _client.post(
      '/merchant/charge',
      body: {
        'token': token,
        'amount': amount,
        if (description != null) 'description': description,
      },
    );

    return result.asMap;
  }
}
