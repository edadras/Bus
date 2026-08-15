import 'package:dio/dio.dart';

import '../models/models.dart';
import 'api_client.dart';

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
