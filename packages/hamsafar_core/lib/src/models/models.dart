import 'package:equatable/equatable.dart';
import '../util/formatters.dart';

/// Parsing helpers. The API is the contract, but a client that hard-crashes on
/// one unexpected null is worse than one that degrades, so every model reads
/// defensively and keeps its required invariants explicit.
T? _as<T>(dynamic value) => value is T ? value : null;

double? _double(dynamic value) => switch (value) {
      final num n => n.toDouble(),
      final String s => double.tryParse(s),
      _ => null,
    };

int? _int(dynamic value) => switch (value) {
      final num n => n.toInt(),
      final String s => int.tryParse(s),
      _ => null,
    };

DateTime? _date(dynamic value) => value is String ? DateTime.tryParse(value) : null;

/// A geographic point.
class LatLngPoint extends Equatable {
  const LatLngPoint(this.lat, this.lng);

  final double lat;
  final double lng;

  static LatLngPoint? tryFrom(Map<String, dynamic>? json) {
    if (json == null) return null;

    final lat = _double(json['lat']);
    final lng = _double(json['lng']);

    return (lat == null || lng == null) ? null : LatLngPoint(lat, lng);
  }

  @override
  List<Object?> get props => [lat, lng];
}

class AppUser extends Equatable {
  const AppUser({
    required this.uuid,
    required this.name,
    required this.mobile,
    this.cityName,
    this.roles = const [],
    this.isDriver = false,
    this.preferences = const {},
  });

  final String uuid;
  final String name;
  final String mobile;
  final String? cityName;
  final List<String> roles;
  final bool isDriver;
  final Map<String, dynamic> preferences;

  factory AppUser.fromJson(Map<String, dynamic> json) => AppUser(
        uuid: _as<String>(json['uuid']) ?? '',
        name: _as<String>(json['name']) ?? '',
        mobile: _as<String>(json['mobile']) ?? '',
        cityName: _as<Map<String, dynamic>>(json['city'])?['name'] as String?,
        roles: (_as<List<dynamic>>(json['roles']) ?? const []).cast<String>(),
        isDriver: json['is_driver'] == true,
        preferences: _as<Map<String, dynamic>>(json['preferences']) ?? const {},
      );

  @override
  List<Object?> get props => [uuid, name, mobile];
}

class Wallet extends Equatable {
  const Wallet({
    required this.balance,
    required this.formattedBalance,
    required this.statusLabel,
    required this.canSpend,
  });

  final int balance;
  final String formattedBalance;
  final String statusLabel;
  final bool canSpend;

  static const empty = Wallet(
    balance: 0,
    formattedBalance: '—',
    statusLabel: '—',
    canSpend: false,
  );

  factory Wallet.fromJson(Map<String, dynamic> json) => Wallet(
        balance: _int(json['balance']) ?? 0,
        formattedBalance: _as<String>(json['formatted_balance']) ?? '—',
        statusLabel: _as<String>(json['status_label']) ?? '—',
        canSpend: json['can_spend'] == true,
      );

  @override
  List<Object?> get props => [balance, canSpend];
}

class LedgerEntry extends Equatable {
  const LedgerEntry({
    required this.id,
    required this.isCredit,
    required this.amount,
    required this.formattedAmount,
    required this.balanceAfter,
    required this.title,
    required this.createdAt,
  });

  final int id;
  final bool isCredit;
  final int amount;
  final String formattedAmount;
  final int balanceAfter;
  final String title;
  final DateTime? createdAt;

  factory LedgerEntry.fromJson(Map<String, dynamic> json) {
    final transaction = _as<Map<String, dynamic>>(json['transaction']);

    return LedgerEntry(
      id: _int(json['id']) ?? 0,
      isCredit: json['direction'] == 'credit',
      amount: _int(json['amount']) ?? 0,
      formattedAmount: _as<String>(json['formatted_amount']) ?? '—',
      balanceAfter: _int(json['balance_after']) ?? 0,
      title: _as<String>(transaction?['type_label']) ??
          _as<String>(json['description']) ??
          Format.tr('common.transaction'),
      createdAt: _date(json['created_at']),
    );
  }

  @override
  List<Object?> get props => [id];
}

class BusStop extends Equatable {
  const BusStop({
    required this.id,
    required this.code,
    required this.name,
    required this.position,
    this.isTerminal = false,
    this.isAccessible = false,
    this.distanceMeters,
    this.isVerifiedData = false,
  });

  final int id;
  final String code;
  final String name;
  final LatLngPoint position;
  final bool isTerminal;
  final bool isAccessible;
  final int? distanceMeters;

  /// False for sample/community geometry. The UI must surface this rather than
  /// letting demo data pass as the published network.
  final bool isVerifiedData;

  factory BusStop.fromJson(Map<String, dynamic> json) => BusStop(
        id: _int(json['id']) ?? 0,
        code: _as<String>(json['code']) ?? '',
        name: _as<String>(json['name']) ?? '',
        position: LatLngPoint(_double(json['lat']) ?? 0, _double(json['lng']) ?? 0),
        isTerminal: json['is_terminal'] == true,
        isAccessible: json['is_accessible'] == true,
        distanceMeters: _int(json['distance_meters']),
        isVerifiedData: json['is_verified_data'] == true,
      );

  @override
  List<Object?> get props => [id];
}

class BusLine extends Equatable {
  const BusLine({
    required this.id,
    required this.code,
    required this.name,
    required this.color,
    this.origin,
    this.destination,
    this.typicalDurationMinutes,
    this.headwayMinutes,
    this.isVerifiedData = false,
  });

  final int id;
  final String code;
  final String name;
  final String color;
  final String? origin;
  final String? destination;
  final int? typicalDurationMinutes;
  final int? headwayMinutes;
  final bool isVerifiedData;

  factory BusLine.fromJson(Map<String, dynamic> json) => BusLine(
        id: _int(json['id']) ?? 0,
        code: _as<String>(json['code']) ?? '',
        name: _as<String>(json['name']) ?? '',
        color: _as<String>(json['color']) ?? '#12B76A',
        origin: _as<String>(json['origin']),
        destination: _as<String>(json['destination']),
        typicalDurationMinutes: _int(json['typical_duration_minutes']),
        headwayMinutes: _int(json['headway_minutes']),
        isVerifiedData: json['is_verified_data'] == true,
      );

  @override
  List<Object?> get props => [id];
}

/// One live bus, as published on the city channel.
class LiveBus extends Equatable {
  const LiveBus({
    required this.tripUuid,
    required this.position,
    this.busNumber,
    this.lineCode,
    this.lineName,
    this.lineColor,
    this.destination,
    this.nextStop,
    this.heading,
    this.speed,
    this.passengerCount = 0,
    this.occupancy = 0,
    this.etaNextStopSeconds,
    this.isOffRoute = false,
    this.isIdle = false,
    this.updatedAt,
  });

  final String tripUuid;
  final LatLngPoint position;
  final String? busNumber;
  final String? lineCode;
  final String? lineName;
  final String? lineColor;
  final String? destination;
  final String? nextStop;
  final double? heading;
  final double? speed;
  final int passengerCount;
  final double occupancy;
  final int? etaNextStopSeconds;
  final bool isOffRoute;
  final bool isIdle;
  final DateTime? updatedAt;

  /// No fresh position for a while: the marker should be shown faded rather
  /// than removed, so the map does not appear to lose buses at random.
  bool get isStale => updatedAt == null || DateTime.now().difference(updatedAt!).inSeconds > 120;

  factory LiveBus.fromJson(Map<String, dynamic> json) => LiveBus(
        tripUuid: _as<String>(json['trip_uuid']) ?? '',
        position: LatLngPoint(_double(json['lat']) ?? 0, _double(json['lng']) ?? 0),
        busNumber: json['bus_number']?.toString(),
        lineCode: json['line_code']?.toString(),
        lineName: _as<String>(json['line_name']),
        lineColor: _as<String>(json['line_color']),
        destination: _as<String>(json['destination']),
        nextStop: _as<String>(json['next_stop']),
        heading: _double(json['heading']),
        speed: _double(json['speed']),
        passengerCount: _int(json['passenger_count']) ?? 0,
        occupancy: _double(json['occupancy']) ?? 0,
        etaNextStopSeconds: _int(json['eta_next_stop_seconds']),
        isOffRoute: json['is_off_route'] == true,
        isIdle: json['is_idle'] == true,
        updatedAt: _date(json['updated_at']),
      );

  @override
  List<Object?> get props => [tripUuid, position, passengerCount];
}

/// A predicted arrival on a stop's board.
class Arrival extends Equatable {
  const Arrival({
    required this.tripUuid,
    required this.etaSeconds,
    required this.etaMinutes,
    required this.confidence,
    required this.isReliable,
    this.busNumber,
    this.lineCode,
    this.lineName,
    this.lineColor,
    this.destination,
    this.passengerCount = 0,
    this.stopsAway = 0,
  });

  final String tripUuid;
  final int etaSeconds;
  final int etaMinutes;
  final double confidence;

  /// When false the UI must present the figure as approximate. Publishing a
  /// low-confidence estimate as if it were firm is how riders stop trusting
  /// the whole board.
  final bool isReliable;

  final String? busNumber;
  final String? lineCode;
  final String? lineName;
  final String? lineColor;
  final String? destination;
  final int passengerCount;
  final int stopsAway;

  factory Arrival.fromJson(Map<String, dynamic> json) {
    final eta = _as<Map<String, dynamic>>(json['eta']) ?? const {};

    return Arrival(
      tripUuid: _as<String>(json['trip_uuid']) ?? '',
      etaSeconds: _int(eta['seconds']) ?? 0,
      etaMinutes: _int(eta['minutes']) ?? 0,
      confidence: _double(eta['confidence']) ?? 0,
      isReliable: eta['reliable'] == true,
      busNumber: json['bus_number']?.toString(),
      lineCode: json['line_code']?.toString(),
      lineName: _as<String>(json['line_name']),
      lineColor: _as<String>(json['line_color']),
      destination: _as<String>(json['destination']),
      passengerCount: _int(json['passenger_count']) ?? 0,
      stopsAway: _int(eta['stops_away']) ?? 0,
    );
  }

  @override
  List<Object?> get props => [tripUuid, etaSeconds];
}

class ActiveRide extends Equatable {
  const ActiveRide({
    required this.uuid,
    required this.status,
    required this.fareAmount,
    required this.formattedFare,
    this.lineName,
    this.lineCode,
    this.busNumber,
    this.destination,
    this.nextStop,
    this.nextStopEtaSeconds,
    this.passengerCount = 0,
    this.boardedAt,
  });

  final String uuid;
  final String status;
  final int fareAmount;
  final String formattedFare;
  final String? lineName;
  final String? lineCode;
  final String? busNumber;
  final String? destination;
  final String? nextStop;
  final int? nextStopEtaSeconds;
  final int passengerCount;
  final DateTime? boardedAt;

  factory ActiveRide.fromJson(Map<String, dynamic> json) {
    final ride = _as<Map<String, dynamic>>(json['passenger_trip']) ?? json;
    final trip = _as<Map<String, dynamic>>(json['trip']);
    final fare = _as<Map<String, dynamic>>(ride['fare']) ?? const {};
    final nextStop = _as<Map<String, dynamic>>(trip?['next_stop']);

    return ActiveRide(
      uuid: _as<String>(ride['uuid']) ?? '',
      status: _as<String>(ride['status']) ?? 'active',
      fareAmount: _int(fare['amount']) ?? 0,
      formattedFare: _as<String>(fare['formatted']) ?? '—',
      lineName: _as<String>(_as<Map<String, dynamic>>(ride['line'])?['name']) ??
          _as<String>(_as<Map<String, dynamic>>(trip?['line'])?['name']),
      lineCode: _as<Map<String, dynamic>>(trip?['line'])?['code']?.toString(),
      busNumber: _as<Map<String, dynamic>>(trip?['bus'])?['number']?.toString(),
      destination: _as<String>(trip?['destination']),
      nextStop: _as<String>(nextStop?['name']),
      nextStopEtaSeconds: _int(nextStop?['eta_seconds']),
      passengerCount: _int(trip?['passenger_count']) ?? 0,
      boardedAt: _date(ride['boarded_at']),
    );
  }

  @override
  List<Object?> get props => [uuid, status, passengerCount];
}

class RideHistoryItem extends Equatable {
  const RideHistoryItem({
    required this.uuid,
    required this.formattedFare,
    required this.statusLabel,
    this.lineCode,
    this.lineName,
    this.boardedAt,
    this.durationSeconds,
    this.boardingStop,
    this.alightingStop,
  });

  final String uuid;
  final String formattedFare;
  final String statusLabel;
  final String? lineCode;
  final String? lineName;
  final DateTime? boardedAt;
  final int? durationSeconds;
  final String? boardingStop;
  final String? alightingStop;

  factory RideHistoryItem.fromJson(Map<String, dynamic> json) {
    final line = _as<Map<String, dynamic>>(json['line']);

    return RideHistoryItem(
      uuid: _as<String>(json['uuid']) ?? '',
      formattedFare: _as<String>(_as<Map<String, dynamic>>(json['fare'])?['formatted']) ?? '—',
      statusLabel: _as<String>(json['status_label']) ?? '',
      lineCode: line?['code']?.toString(),
      lineName: _as<String>(line?['name']),
      boardedAt: _date(json['boarded_at']),
      durationSeconds: _int(json['duration_seconds']),
      boardingStop: _as<String>(json['boarding_stop']),
      alightingStop: _as<String>(json['alighting_stop']),
    );
  }

  @override
  List<Object?> get props => [uuid];
}

class Complaint extends Equatable {
  const Complaint({
    required this.uuid,
    required this.reference,
    required this.subject,
    required this.statusLabel,
    required this.statusColor,
    required this.isOpen,
    this.body,
    this.categoryLabel,
    this.createdAt,
    this.status,
    this.satisfactionRating,
    this.resolutionNote,
    this.messages = const [],
  });

  final String uuid;
  final String reference;
  final String subject;
  final String statusLabel;
  final String statusColor;
  final bool isOpen;
  final String? body;
  final String? categoryLabel;
  final DateTime? createdAt;
  final String? status;

  /// One to five, once the passenger has said whether the outcome was any
  /// good. Null means they have not been asked yet or have not answered.
  final int? satisfactionRating;
  final String? resolutionNote;
  final List<ComplaintMessage> messages;

  /// Resolved, and not yet rated: the only moment worth asking.
  bool get awaitsRating => status == 'resolved' && satisfactionRating == null;

  factory Complaint.fromJson(Map<String, dynamic> json) => Complaint(
        uuid: _as<String>(json['uuid']) ?? '',
        reference: _as<String>(json['reference']) ?? '',
        subject: _as<String>(json['subject']) ?? '',
        statusLabel: _as<String>(json['status_label']) ?? '',
        statusColor: _as<String>(json['status_color']) ?? 'neutral',
        isOpen: json['is_open'] == true,
        body: _as<String>(json['body']),
        categoryLabel: _as<String>(json['category_label']),
        createdAt: _date(json['created_at']),
        status: _as<String>(json['status']),
        satisfactionRating: _int(json['satisfaction_rating']),
        resolutionNote: _as<String>(json['resolution_note']),
        messages: (_as<List<dynamic>>(json['messages']) ?? const [])
            .cast<Map<String, dynamic>>()
            .map(ComplaintMessage.fromJson)
            .toList(),
      );

  @override
  List<Object?> get props => [uuid, statusLabel, satisfactionRating, messages.length];
}

class ComplaintMessage extends Equatable {
  const ComplaintMessage({
    required this.id,
    required this.body,
    required this.isFromSupport,
    this.authorName,
    this.createdAt,
  });

  final int id;
  final String body;
  final bool isFromSupport;
  final String? authorName;
  final DateTime? createdAt;

  factory ComplaintMessage.fromJson(Map<String, dynamic> json) => ComplaintMessage(
        id: _int(json['id']) ?? 0,
        body: _as<String>(json['body']) ?? '',
        isFromSupport: json['author_type'] == 'agent',
        authorName: _as<String>(json['author_name']),
        createdAt: _date(json['created_at']),
      );

  @override
  List<Object?> get props => [id];
}

class MapConfig extends Equatable {
  const MapConfig({
    required this.tileUrl,
    required this.attribution,
    required this.maxZoom,
    required this.center,
    required this.zoom,
    this.cityId,
    this.cityName,
  });

  final String tileUrl;
  final String attribution;
  final int maxZoom;
  final LatLngPoint center;
  final double zoom;

  /// The city this configuration is for. Carried here because it is the one
  /// payload every client fetches before anything else, signed in or not, and
  /// the live bus channel is named after it.
  final int? cityId;
  final String? cityName;

  static const fallback = MapConfig(
    tileUrl: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19,
    center: LatLngPoint(27.1832, 56.2666),
    zoom: 13,
  );

  factory MapConfig.fromJson(Map<String, dynamic> json) {
    final provider = _as<Map<String, dynamic>>(json['provider']) ?? const {};
    final city = _as<Map<String, dynamic>>(json['city']);

    return MapConfig(
      cityId: _int(city?['id']),
      cityName: _as<String>(city?['name']),
      tileUrl: _as<String>(provider['tile_url']) ?? fallback.tileUrl,
      attribution: _as<String>(provider['attribution']) ?? '',
      maxZoom: _int(provider['max_zoom']) ?? 19,
      center: LatLngPoint.tryFrom(_as<Map<String, dynamic>>(json['center'])) ?? fallback.center,
      zoom: _double(json['zoom']) ?? 13,
    );
  }

  @override
  List<Object?> get props => [tileUrl, center, zoom];
}

/// One entry in the in-app notification inbox.
///
/// The server stores each notification's payload as free-form JSON keyed by
/// type, so only `title` and `body` are treated as guaranteed; everything else
/// stays in [data] for the screens that know what to do with it.
class AppNotification extends Equatable {
  const AppNotification({
    required this.id,
    required this.type,
    required this.read,
    this.title,
    this.body,
    this.data = const {},
    this.createdAt,
  });

  final String id;
  final String type;
  final bool read;
  final String? title;
  final String? body;
  final Map<String, dynamic> data;
  final DateTime? createdAt;

  factory AppNotification.fromJson(Map<String, dynamic> json) => AppNotification(
        id: _as<String>(json['id']) ?? '',
        type: _as<String>(json['type']) ?? 'general',
        read: json['read'] == true,
        title: _as<String>(json['title']),
        body: _as<String>(json['body']),
        data: _as<Map<String, dynamic>>(json['data']) ?? const {},
        createdAt: _date(json['created_at']),
      );

  AppNotification copyWith({bool? read}) => AppNotification(
        id: id,
        type: type,
        read: read ?? this.read,
        title: title,
        body: body,
        data: data,
        createdAt: createdAt,
      );

  @override
  List<Object?> get props => [id, read];
}

/// One suggested way of getting from A to B.
///
/// A journey is either a walk or a sequence of rides; `legs` is empty for the
/// walk. The flattened `line`/`boardAt`/`alightAt` fields mirror the first and
/// last leg so a compact row can render without unpacking the whole thing.
class JourneyOption extends Equatable {
  const JourneyOption({
    required this.mode,
    required this.transfers,
    required this.totalMinutes,
    required this.legs,
    this.walkMinutes = 0,
    this.totalWalkMeters = 0,
    this.walkToStopMeters = 0,
    this.walkFromStopMeters = 0,
    this.stopsCount = 0,
    this.rideDistanceMeters = 0,
  });

  final String mode;
  final int transfers;
  final int totalMinutes;
  final List<JourneyLeg> legs;
  final int walkMinutes;
  final int totalWalkMeters;

  /// The two ends of the walk, kept apart from the total so the itinerary can
  /// say *where* the walking happens instead of only how much there is.
  final int walkToStopMeters;
  final int walkFromStopMeters;
  final int stopsCount;
  final int rideDistanceMeters;

  bool get isWalk => mode == 'walk';

  factory JourneyOption.fromJson(Map<String, dynamic> json) => JourneyOption(
        mode: _as<String>(json['mode']) ?? 'bus',
        transfers: _int(json['transfers']) ?? 0,
        totalMinutes: _int(json['estimated_total_minutes']) ?? 0,
        walkMinutes: _int(json['walk_minutes']) ?? 0,
        totalWalkMeters: _int(json['total_walk_meters']) ?? 0,
        walkToStopMeters: _int(json['walk_to_stop_meters']) ?? 0,
        walkFromStopMeters: _int(json['walk_from_stop_meters']) ?? 0,
        stopsCount: _int(json['stops_count']) ?? 0,
        rideDistanceMeters: _int(json['ride_distance_meters']) ?? 0,
        legs: (_as<List<dynamic>>(json['legs']) ?? const [])
            .cast<Map<String, dynamic>>()
            .map(JourneyLeg.fromJson)
            .toList(),
      );

  @override
  List<Object?> get props => [mode, transfers, totalMinutes, legs.length];
}

/// One ride within a journey: board this line here, get off there.
class JourneyLeg extends Equatable {
  const JourneyLeg({
    required this.lineCode,
    required this.lineName,
    required this.boardStopName,
    required this.alightStopName,
    this.lineColor,
    this.stopsCount = 0,
    this.rideMinutes = 0,
    this.headwayMinutes,
  });

  final String lineCode;
  final String lineName;
  final String boardStopName;
  final String alightStopName;
  final String? lineColor;
  final int stopsCount;
  final int rideMinutes;
  final int? headwayMinutes;

  factory JourneyLeg.fromJson(Map<String, dynamic> json) {
    final line = _as<Map<String, dynamic>>(json['line']) ?? const {};
    final board = _as<Map<String, dynamic>>(json['board_at']) ?? const {};
    final alight = _as<Map<String, dynamic>>(json['alight_at']) ?? const {};

    return JourneyLeg(
      lineCode: _as<String>(line['code']) ?? '—',
      lineName: _as<String>(line['name']) ?? '',
      lineColor: _as<String>(line['color']),
      boardStopName: _as<String>(board['name']) ?? '—',
      alightStopName: _as<String>(alight['name']) ?? '—',
      stopsCount: _int(json['stops_count']) ?? 0,
      rideMinutes: _int(json['ride_minutes']) ?? 0,
      headwayMinutes: _int(json['headway_minutes']),
    );
  }

  @override
  List<Object?> get props => [lineCode, boardStopName, alightStopName];
}

/// The planner's answer: options, and — when there are none — why.
class JourneyPlan extends Equatable {
  const JourneyPlan({required this.options, this.reason});

  final List<JourneyOption> options;

  /// `no_stop_within_walking_distance` or `no_route_found`. Present only when
  /// there is nothing to offer, so the app can say why rather than show a
  /// blank list.
  final String? reason;

  factory JourneyPlan.fromJson(Map<String, dynamic> json) => JourneyPlan(
        options: (_as<List<dynamic>>(json['options']) ?? const [])
            .cast<Map<String, dynamic>>()
            .map(JourneyOption.fromJson)
            .toList(),
        reason: _as<String>(json['reason']),
      );

  @override
  List<Object?> get props => [options.length, reason];
}

/// A line with the routes it runs.
class LineDetail extends Equatable {
  const LineDetail({required this.line, this.routes = const []});

  final BusLine line;
  final List<RouteSummary> routes;

  factory LineDetail.fromJson(Map<String, dynamic> json) => LineDetail(
        line: BusLine.fromJson(json),
        routes: (_as<List<dynamic>>(json['routes']) ?? const [])
            .cast<Map<String, dynamic>>()
            .map(RouteSummary.fromJson)
            .toList(),
      );

  @override
  List<Object?> get props => [line.id, routes.length];
}

/// One direction of a line, as listed on the line itself.
class RouteSummary extends Equatable {
  const RouteSummary({
    required this.id,
    required this.name,
    required this.directionLabel,
    this.distanceMeters,
    this.originName,
    this.destinationName,
  });

  final int id;
  final String name;
  final String directionLabel;
  final int? distanceMeters;
  final String? originName;
  final String? destinationName;

  factory RouteSummary.fromJson(Map<String, dynamic> json) => RouteSummary(
        id: _int(json['id']) ?? 0,
        name: _as<String>(json['name']) ?? '',
        directionLabel: _as<String>(json['direction_label']) ?? '',
        distanceMeters: _int(json['distance_meters']),
        originName: _as<Map<String, dynamic>>(json['origin'])?['name'] as String?,
        destinationName: _as<Map<String, dynamic>>(json['destination'])?['name'] as String?,
      );

  @override
  List<Object?> get props => [id];
}

/// One route with its stops in order — the timeline a passenger reads to work
/// out whether this line goes anywhere near them.
class RouteDetail extends Equatable {
  const RouteDetail({
    required this.id,
    required this.name,
    required this.directionLabel,
    this.distanceMeters,
    this.stops = const [],
  });

  final int id;
  final String name;
  final String directionLabel;
  final int? distanceMeters;
  final List<RouteStop> stops;

  factory RouteDetail.fromJson(Map<String, dynamic> json) => RouteDetail(
        id: _int(json['id']) ?? 0,
        name: _as<String>(json['name']) ?? '',
        directionLabel: _as<String>(json['direction_label']) ?? '',
        distanceMeters: _int(json['distance_meters']),
        stops: (_as<List<dynamic>>(json['stops']) ?? const [])
            .cast<Map<String, dynamic>>()
            .map(RouteStop.fromJson)
            .toList(),
      );

  @override
  List<Object?> get props => [id, stops.length];
}

/// A stop's place in a route.
class RouteStop extends Equatable {
  const RouteStop({
    required this.sequence,
    required this.stop,
    this.distanceFromStart,
    this.isTimepoint = false,
  });

  final int sequence;
  final BusStop stop;
  final int? distanceFromStart;
  final bool isTimepoint;

  factory RouteStop.fromJson(Map<String, dynamic> json) => RouteStop(
        sequence: _int(json['sequence']) ?? 0,
        stop: BusStop.fromJson(_as<Map<String, dynamic>>(json['stop']) ?? const {}),
        distanceFromStart: _int(json['distance_from_start']),
        isTimepoint: json['is_timepoint'] == true,
      );

  @override
  List<Object?> get props => [sequence, stop.id];
}

/// An estimate of when one bus reaches one stop.
class StopEta extends Equatable {
  const StopEta({
    required this.seconds,
    required this.minutes,
    required this.stopsAway,
    this.isReliable = false,
  });

  final int seconds;
  final int minutes;
  final int stopsAway;

  /// Below the server's confidence threshold the figure is shown as
  /// approximate rather than dropped: "about 12 minutes" beats a blank.
  final bool isReliable;

  factory StopEta.fromJson(Map<String, dynamic> json) => StopEta(
        seconds: _int(json['seconds']) ?? 0,
        minutes: _int(json['minutes']) ?? 0,
        stopsAway: _int(json['stops_away']) ?? 0,
        isReliable: json['reliable'] == true,
      );

  @override
  List<Object?> get props => [seconds, stopsAway, isReliable];
}

// ── Taxi ──────────────────────────────────────────────────────────────────

/// The three products a taxi can be running.
///
/// Which one applies belongs to the driver's open shift, not to the car: the
/// same vehicle offers a fixed line in the morning and a metered ride in the
/// afternoon. The colour is part of the model because it is how a rider tells
/// them apart on a map, and it has to be the same colour everywhere.
enum TaxiServiceType {
  line('line'),
  charter('charter'),
  meter('meter');

  const TaxiServiceType(this.value);

  final String value;

  static TaxiServiceType from(String? value) => switch (value) {
        'charter' => TaxiServiceType.charter,
        'meter' => TaxiServiceType.meter,
        _ => TaxiServiceType.line,
      };

  String get label => Format.tr('taxi.modes.$value');

  /// A fare that is known before the ride starts, and that the passenger has
  /// to confirm by sending back the figure they were shown.
  bool get isPricedUpFront => this != TaxiServiceType.meter;

  /// A meter runs until somebody stops it; the other two do not.
  bool get isOpenEnded => this == TaxiServiceType.meter;
}

/// A taxi as it appears on the passenger's map: where it is, what it offers,
/// and nothing that identifies the person driving it.
class NearbyTaxi extends Equatable {
  const NearbyTaxi({
    required this.uuid,
    required this.point,
    required this.serviceType,
    this.lineCode,
    this.lineName,
    this.seatsFree,
    this.isAvailable = true,
    this.distanceMeters,
  });

  final String uuid;
  final LatLngPoint point;
  final TaxiServiceType serviceType;
  final String? lineCode;
  final String? lineName;
  final int? seatsFree;
  final bool isAvailable;
  final int? distanceMeters;

  factory NearbyTaxi.fromJson(Map<String, dynamic> json) => NearbyTaxi(
        uuid: _as<String>(json['uuid']) ?? '',
        point: LatLngPoint(_double(json['lat']) ?? 0, _double(json['lng']) ?? 0),
        serviceType: TaxiServiceType.from(_as<String>(json['service_type'])),
        lineCode: json['line_code']?.toString(),
        lineName: _as<String>(json['line_name']),
        seatsFree: _int(json['seats_free']),
        isAvailable: json['is_available'] != false,
        distanceMeters: _int(json['distance_meters']),
      );

  @override
  List<Object?> get props => [uuid, point, serviceType, isAvailable];
}

/// A shared-taxi line: a fixed origin, a fixed destination and one price.
class TaxiLine extends Equatable {
  const TaxiLine({
    required this.id,
    required this.code,
    required this.name,
    required this.flatFare,
    required this.formattedFare,
    required this.isVerifiedData,
    this.origin,
    this.destination,
    this.color,
    this.typicalDurationMinutes,
  });

  final int id;
  final String code;
  final String name;
  final int flatFare;
  final String formattedFare;

  /// False for imported sample geometry, which must never be shown as though
  /// it were the published network.
  final bool isVerifiedData;
  final String? origin;
  final String? destination;
  final String? color;
  final int? typicalDurationMinutes;

  factory TaxiLine.fromJson(Map<String, dynamic> json) => TaxiLine(
        id: _int(json['id']) ?? 0,
        code: json['code']?.toString() ?? '',
        name: _as<String>(json['name']) ?? '',
        flatFare: _int(json['flat_fare']) ?? 0,
        formattedFare: _as<String>(json['formatted_fare']) ?? '—',
        isVerifiedData: json['is_verified_data'] != false,
        origin: _as<String>(json['origin']),
        destination: _as<String>(json['destination']),
        color: _as<String>(json['color']),
        typicalDurationMinutes: _int(json['typical_duration_minutes']),
      );

  @override
  List<Object?> get props => [id];
}

/// What a scan found: the car, what it is offering, and what it would cost.
class TaxiScanResult extends Equatable {
  const TaxiScanResult({
    required this.serviceType,
    required this.quote,
    required this.taxiNumber,
    required this.requiresAmountConfirmation,
    this.plate,
    this.model,
    this.color,
    this.line,
  });

  final TaxiServiceType serviceType;
  final TaxiFareQuote quote;
  final String taxiNumber;

  /// True when the passenger must send back the figure they were shown, which
  /// is what makes charging a price they never saw impossible.
  final bool requiresAmountConfirmation;
  final String? plate;
  final String? model;
  final String? color;
  final TaxiLine? line;

  factory TaxiScanResult.fromJson(Map<String, dynamic> json) {
    final taxi = _as<Map<String, dynamic>>(json['taxi']);
    final line = _as<Map<String, dynamic>>(json['line']);

    return TaxiScanResult(
      serviceType: TaxiServiceType.from(_as<String>(json['service_type'])),
      quote: TaxiFareQuote.fromJson(_as<Map<String, dynamic>>(json['quote']) ?? const {}),
      taxiNumber: taxi?['taxi_number']?.toString() ?? '—',
      requiresAmountConfirmation: json['requires_amount_confirmation'] == true,
      plate: _as<String>(taxi?['plate']),
      model: _as<String>(taxi?['model']),
      color: _as<String>(taxi?['color']),
      line: line == null ? null : TaxiLine.fromJson(line),
    );
  }

  @override
  List<Object?> get props => [taxiNumber, serviceType, quote];
}

/// A priced fare with its arithmetic attached.
///
/// The breakdown travels with the quote rather than being recomputed for
/// display: a metered fare is the one charge nobody can check in advance, so
/// the receipt has to be the sum that actually ran.
class TaxiFareQuote extends Equatable {
  const TaxiFareQuote({
    required this.amount,
    required this.formattedAmount,
    this.breakdown = const {},
    this.distanceMeters,
    this.waitingSeconds,
  });

  final int amount;
  final String formattedAmount;
  final Map<String, dynamic> breakdown;
  final int? distanceMeters;
  final int? waitingSeconds;

  factory TaxiFareQuote.fromJson(Map<String, dynamic> json) {
    final breakdown = _as<Map<String, dynamic>>(json['breakdown']) ?? const {};

    return TaxiFareQuote(
      amount: _int(json['amount']) ?? 0,
      formattedAmount: _as<String>(json['formatted']) ?? Format.money(_int(json['amount']) ?? 0),
      breakdown: breakdown,
      // The metered breakdown is where these live; a flat or charter fare has
      // no distance to speak of and leaves them null.
      distanceMeters: _int(breakdown['distance_meters']),
      waitingSeconds: _int(breakdown['waiting_seconds']),
    );
  }

  @override
  List<Object?> get props => [amount, distanceMeters, waitingSeconds];
}

/// One taxi ride, from either side of the transaction.
class TaxiRide extends Equatable {
  const TaxiRide({
    required this.uuid,
    required this.serviceType,
    required this.status,
    required this.statusLabel,
    required this.statusColor,
    required this.isOpen,
    required this.fareAmount,
    required this.formattedFare,
    required this.outstandingAmount,
    this.breakdown = const {},
    this.distanceMeters = 0,
    this.waitingSeconds = 0,
    this.durationSeconds = 0,
    this.taxiNumber,
    this.plate,
    this.lineCode,
    this.lineName,
    this.driverName,
    this.startedAt,
    this.endedAt,
    this.currentFare,
  });

  final String uuid;
  final TaxiServiceType serviceType;
  final String status;
  final String statusLabel;
  final String statusColor;
  final bool isOpen;

  /// Money that actually moved. An unpaid metered ride carries nothing here —
  /// the debt is in [outstandingAmount] instead, so a driver's own figures
  /// never disagree with their payout.
  final int fareAmount;
  final String formattedFare;
  final int outstandingAmount;
  final Map<String, dynamic> breakdown;
  final int distanceMeters;
  final int waitingSeconds;
  final int durationSeconds;
  final String? taxiNumber;
  final String? plate;
  final String? lineCode;
  final String? lineName;
  final String? driverName;
  final DateTime? startedAt;
  final DateTime? endedAt;

  /// Present while a meter is running: the total as it stands right now, so
  /// the price is something the passenger watches rather than a surprise at
  /// the destination.
  final TaxiFareQuote? currentFare;

  bool get isUnpaid => status == 'unpaid';

  factory TaxiRide.fromJson(Map<String, dynamic> json) {
    final taxi = _as<Map<String, dynamic>>(json['taxi']);
    final line = _as<Map<String, dynamic>>(json['line']);
    final current = _as<Map<String, dynamic>>(json['current_fare']);

    return TaxiRide(
      uuid: _as<String>(json['uuid']) ?? '',
      serviceType: TaxiServiceType.from(_as<String>(json['service_type'])),
      status: _as<String>(json['status']) ?? 'active',
      statusLabel: _as<String>(json['status_label']) ?? '',
      statusColor: _as<String>(json['status_color']) ?? 'neutral',
      isOpen: json['is_open'] == true,
      fareAmount: _int(json['fare_amount']) ?? 0,
      formattedFare: _as<String>(json['formatted_fare']) ?? '—',
      outstandingAmount: _int(json['outstanding_amount']) ?? 0,
      breakdown: _as<Map<String, dynamic>>(json['fare_breakdown']) ?? const {},
      distanceMeters: _int(json['distance_meters']) ?? 0,
      waitingSeconds: _int(json['waiting_seconds']) ?? 0,
      durationSeconds: _int(json['duration_seconds']) ?? 0,
      taxiNumber: taxi?['taxi_number']?.toString(),
      plate: _as<String>(taxi?['plate']),
      lineCode: line?['code']?.toString(),
      lineName: _as<String>(line?['name']),
      driverName: _as<String>(json['driver_name']),
      startedAt: _date(json['started_at']),
      endedAt: _date(json['ended_at']),
      currentFare: current == null ? null : TaxiFareQuote.fromJson(current),
    );
  }

  @override
  List<Object?> get props => [uuid, status, fareAmount, outstandingAmount, currentFare];
}

/// A driver's payout request.
class TaxiSettlement extends Equatable {
  const TaxiSettlement({
    required this.uuid,
    required this.reference,
    required this.status,
    required this.statusLabel,
    required this.statusColor,
    required this.rideCount,
    required this.netAmount,
    required this.formattedNet,
    this.grossAmount = 0,
    this.commissionAmount = 0,
    this.periodStart,
    this.periodEnd,
    this.paymentReference,
    this.rejectionReason,
    this.createdAt,
    this.paidAt,
  });

  final String uuid;
  final String reference;
  final String status;
  final String statusLabel;
  final String statusColor;
  final int rideCount;
  final int netAmount;
  final String formattedNet;
  final int grossAmount;
  final int commissionAmount;
  final String? periodStart;
  final String? periodEnd;
  final String? paymentReference;
  final String? rejectionReason;
  final DateTime? createdAt;
  final DateTime? paidAt;

  factory TaxiSettlement.fromJson(Map<String, dynamic> json) => TaxiSettlement(
        uuid: _as<String>(json['uuid']) ?? '',
        reference: _as<String>(json['reference']) ?? '',
        status: _as<String>(json['status']) ?? 'requested',
        statusLabel: _as<String>(json['status_label']) ?? '',
        statusColor: _as<String>(json['status_color']) ?? 'neutral',
        rideCount: _int(json['ride_count']) ?? 0,
        netAmount: _int(json['net_amount']) ?? 0,
        formattedNet: _as<String>(json['formatted_net']) ?? '—',
        grossAmount: _int(json['gross_amount']) ?? 0,
        commissionAmount: _int(json['commission_amount']) ?? 0,
        periodStart: _as<String>(json['period_start']),
        periodEnd: _as<String>(json['period_end']),
        paymentReference: _as<String>(json['payment_reference']),
        rejectionReason: _as<String>(json['rejection_reason']),
        createdAt: _date(json['created_at']),
        paidAt: _date(json['paid_at']),
      );

  @override
  List<Object?> get props => [uuid, status];
}

// ── School transport ──────────────────────────────────────────────────────

/// A school service company a family may choose between.
///
/// Only approved companies ever reach the app, so [isApproved] is a display
/// detail here rather than a filter: the server has already decided.
class SchoolCompany extends Equatable {
  const SchoolCompany({
    required this.uuid,
    required this.name,
    required this.isApproved,
    this.legalName,
    this.phone,
    this.description,
    this.rating,
    this.contractCount = 0,
    this.vehicleCount,
    this.routeCount,
    this.licenseNumber,
  });

  final String uuid;
  final String name;
  final bool isApproved;
  final String? legalName;
  final String? phone;
  final String? description;
  final double? rating;
  final int contractCount;
  final int? vehicleCount;
  final int? routeCount;
  final String? licenseNumber;

  factory SchoolCompany.fromJson(Map<String, dynamic> json) => SchoolCompany(
        uuid: _as<String>(json['uuid']) ?? '',
        name: _as<String>(json['name']) ?? '',
        isApproved: json['is_approved'] == true,
        legalName: _as<String>(json['legal_name']),
        phone: _as<String>(json['phone']),
        description: _as<String>(json['description']),
        rating: _double(json['rating']),
        contractCount: _int(json['contract_count']) ?? 0,
        vehicleCount: _int(json['vehicle_count']),
        routeCount: _int(json['route_count']),
        licenseNumber: _as<String>(json['license_number']),
      );

  @override
  List<Object?> get props => [uuid];
}

class SchoolSummary extends Equatable {
  const SchoolSummary({
    required this.uuid,
    required this.name,
    this.gender,
    this.level,
    this.address,
    this.point,
    this.startsAt,
    this.endsAt,
  });

  final String uuid;
  final String name;
  final String? gender;
  final String? level;
  final String? address;
  final LatLngPoint? point;
  final String? startsAt;
  final String? endsAt;

  factory SchoolSummary.fromJson(Map<String, dynamic> json) => SchoolSummary(
        uuid: _as<String>(json['uuid']) ?? '',
        name: _as<String>(json['name']) ?? '',
        gender: _as<String>(json['gender']),
        level: _as<String>(json['level']),
        address: _as<String>(json['address']),
        point: _double(json['lat']) == null || _double(json['lng']) == null
            ? null
            : LatLngPoint(_double(json['lat'])!, _double(json['lng'])!),
        startsAt: _as<String>(json['starts_at']),
        endsAt: _as<String>(json['ends_at']),
      );

  @override
  List<Object?> get props => [uuid];
}

/// A child. Contracts are per child rather than per family, because two
/// children at two schools are two arrangements.
class SchoolStudent extends Equatable {
  const SchoolStudent({
    required this.uuid,
    required this.name,
    required this.isActive,
    this.firstName,
    this.lastName,
    this.grade,
    this.classroom,
    this.gender,
    this.pickupAddress,
    this.pickupPoint,
    this.medicalNotes,
    this.emergencyContactName,
    this.emergencyContactPhone,
    this.schoolUuid,
    this.schoolName,
  });

  final String uuid;
  final String name;
  final bool isActive;
  final String? firstName;
  final String? lastName;
  final String? grade;
  final String? classroom;
  final String? gender;
  final String? pickupAddress;
  final LatLngPoint? pickupPoint;

  /// Carried so a driver has it in the moment it matters rather than in a file
  /// somebody would have to go and find.
  final String? medicalNotes;
  final String? emergencyContactName;
  final String? emergencyContactPhone;
  final String? schoolUuid;
  final String? schoolName;

  factory SchoolStudent.fromJson(Map<String, dynamic> json) {
    final school = _as<Map<String, dynamic>>(json['school']);

    return SchoolStudent(
      uuid: _as<String>(json['uuid']) ?? '',
      name: _as<String>(json['name']) ?? '',
      isActive: json['is_active'] != false,
      firstName: _as<String>(json['first_name']),
      lastName: _as<String>(json['last_name']),
      grade: _as<String>(json['grade']),
      classroom: _as<String>(json['classroom']),
      gender: _as<String>(json['gender']),
      pickupAddress: _as<String>(json['pickup_address']),
      pickupPoint: _double(json['pickup_lat']) == null || _double(json['pickup_lng']) == null
          ? null
          : LatLngPoint(_double(json['pickup_lat'])!, _double(json['pickup_lng'])!),
      medicalNotes: _as<String>(json['medical_notes']),
      emergencyContactName: _as<String>(json['emergency_contact_name']),
      emergencyContactPhone: _as<String>(json['emergency_contact_phone']),
      schoolUuid: _as<String>(school?['uuid']),
      schoolName: _as<String>(school?['name']),
    );
  }

  @override
  List<Object?> get props => [uuid, name, isActive];
}

/// A school service arrangement, in one of four states: requested, approved
/// with a fee named, active with a seat on a route, or ended.
class SchoolContract extends Equatable {
  const SchoolContract({
    required this.uuid,
    required this.reference,
    required this.status,
    required this.statusLabel,
    required this.statusColor,
    required this.direction,
    required this.directionLabel,
    required this.feeAmount,
    required this.formattedFee,
    this.paymentCycle,
    this.startsOn,
    this.endsOn,
    this.pickupAddress,
    this.companyName,
    this.companyPhone,
    this.schoolName,
    this.studentUuid,
    this.studentName,
    this.routeName,
    this.vehiclePlate,
    this.driverName,
    this.rejectionReason,
    this.companyNote,
  });

  final String uuid;
  final String reference;
  final String status;
  final String statusLabel;
  final String statusColor;
  final String direction;
  final String directionLabel;
  final int feeAmount;
  final String formattedFee;
  final String? paymentCycle;
  final String? startsOn;
  final String? endsOn;
  final String? pickupAddress;
  final String? companyName;
  final String? companyPhone;
  final String? schoolName;
  final String? studentUuid;
  final String? studentName;

  /// Null until the company places the child on a van, which is the step that
  /// turns an agreement into a seat.
  final String? routeName;
  final String? vehiclePlate;
  final String? driverName;
  final String? rejectionReason;
  final String? companyNote;

  bool get isActive => status == 'active';

  bool get awaitingCompany => status == 'requested';

  bool get hasSeat => routeName != null;

  factory SchoolContract.fromJson(Map<String, dynamic> json) {
    final company = _as<Map<String, dynamic>>(json['company']);
    final school = _as<Map<String, dynamic>>(json['school']);
    final student = _as<Map<String, dynamic>>(json['student']);
    final route = _as<Map<String, dynamic>>(json['route']);

    return SchoolContract(
      uuid: _as<String>(json['uuid']) ?? '',
      reference: _as<String>(json['reference']) ?? '',
      status: _as<String>(json['status']) ?? 'requested',
      statusLabel: _as<String>(json['status_label']) ?? '',
      statusColor: _as<String>(json['status_color']) ?? 'neutral',
      direction: _as<String>(json['direction']) ?? 'both',
      directionLabel: _as<String>(json['direction_label']) ?? '',
      feeAmount: _int(json['payable_amount']) ?? _int(json['fee_amount']) ?? 0,
      formattedFee: _as<String>(json['formatted_fee']) ?? '—',
      paymentCycle: _as<String>(json['payment_cycle']),
      startsOn: _as<String>(json['starts_on']),
      endsOn: _as<String>(json['ends_on']),
      pickupAddress: _as<String>(json['pickup_address']),
      companyName: _as<String>(company?['name']),
      companyPhone: _as<String>(company?['phone']),
      schoolName: _as<String>(school?['name']),
      studentUuid: _as<String>(student?['uuid']),
      studentName: _as<String>(student?['name']),
      routeName: _as<String>(route?['name']),
      vehiclePlate: _as<String>(route?['vehicle_plate']),
      driverName: _as<String>(route?['driver_name']),
      rejectionReason: _as<String>(json['rejection_reason']),
      companyNote: _as<String>(json['company_note']),
    );
  }

  @override
  List<Object?> get props => [uuid, status, routeName];
}

class SchoolInvoice extends Equatable {
  const SchoolInvoice({
    required this.uuid,
    required this.reference,
    required this.status,
    required this.statusLabel,
    required this.statusColor,
    required this.isPayable,
    required this.isOverdue,
    required this.amount,
    required this.formattedAmount,
    this.periodStart,
    this.periodEnd,
    this.dueOn,
    this.studentName,
    this.paidAt,
  });

  final String uuid;
  final String reference;
  final String status;
  final String statusLabel;
  final String statusColor;
  final bool isPayable;
  final bool isOverdue;
  final int amount;
  final String formattedAmount;
  final String? periodStart;
  final String? periodEnd;
  final String? dueOn;
  final String? studentName;
  final DateTime? paidAt;

  factory SchoolInvoice.fromJson(Map<String, dynamic> json) => SchoolInvoice(
        uuid: _as<String>(json['uuid']) ?? '',
        reference: _as<String>(json['reference']) ?? '',
        status: _as<String>(json['status']) ?? 'pending',
        statusLabel: _as<String>(json['status_label']) ?? '',
        statusColor: _as<String>(json['status_color']) ?? 'neutral',
        isPayable: json['is_payable'] == true,
        isOverdue: json['is_overdue'] == true,
        amount: _int(json['amount']) ?? 0,
        formattedAmount: _as<String>(json['formatted_amount']) ?? '—',
        periodStart: _as<String>(json['period_start']),
        periodEnd: _as<String>(json['period_end']),
        dueOn: _as<String>(json['due_on']),
        studentName:
            _as<String>(_as<Map<String, dynamic>>(json['contract'])?['student_name']),
        paidAt: _date(json['paid_at']),
      );

  @override
  List<Object?> get props => [uuid, status];
}

/// One run of a school service route, as the driver app reads it.
class SchoolTrip extends Equatable {
  const SchoolTrip({
    required this.uuid,
    required this.direction,
    required this.directionLabel,
    required this.status,
    required this.statusLabel,
    required this.statusColor,
    required this.isLive,
    required this.expectedCount,
    required this.pickedUpCount,
    required this.droppedOffCount,
    required this.absentCount,
    this.serviceDate,
    this.routeName,
    this.schoolName,
    this.schoolAddress,
    this.schoolPoint,
    this.vehiclePlate,
    this.pickupStartsAt,
    this.dropoffStartsAt,
    this.startedAt,
    this.endedAt,
    this.students = const [],
  });

  final String uuid;
  final String direction;
  final String directionLabel;
  final String status;
  final String statusLabel;
  final String statusColor;
  final bool isLive;
  final int expectedCount;
  final int pickedUpCount;
  final int droppedOffCount;
  final int absentCount;
  final String? serviceDate;
  final String? routeName;
  final String? schoolName;
  final String? schoolAddress;
  final LatLngPoint? schoolPoint;
  final String? vehiclePlate;
  final String? pickupStartsAt;
  final String? dropoffStartsAt;
  final DateTime? startedAt;
  final DateTime? endedAt;

  /// The manifest, already in collection order.
  final List<SchoolTripStudent> students;

  bool get isMorning => direction == 'to_school';

  bool get isScheduled => status == 'scheduled';

  /// Everyone accounted for: nobody is still waiting to be checked.
  bool get isSettled => students.every((student) => student.isSettled);

  factory SchoolTrip.fromJson(Map<String, dynamic> json) {
    final route = _as<Map<String, dynamic>>(json['route']);
    final school = _as<Map<String, dynamic>>(route?['school']);
    final vehicle = _as<Map<String, dynamic>>(json['vehicle']);

    return SchoolTrip(
      uuid: _as<String>(json['uuid']) ?? '',
      direction: _as<String>(json['direction']) ?? 'to_school',
      directionLabel: _as<String>(json['direction_label']) ?? '',
      status: _as<String>(json['status']) ?? 'scheduled',
      statusLabel: _as<String>(json['status_label']) ?? '',
      statusColor: _as<String>(json['status_color']) ?? 'neutral',
      isLive: json['is_live'] == true,
      expectedCount: _int(json['expected_count']) ?? 0,
      pickedUpCount: _int(json['picked_up_count']) ?? 0,
      droppedOffCount: _int(json['dropped_off_count']) ?? 0,
      absentCount: _int(json['absent_count']) ?? 0,
      serviceDate: _as<String>(json['service_date']),
      routeName: _as<String>(route?['name']),
      schoolName: _as<String>(school?['name']),
      schoolAddress: _as<String>(school?['address']),
      schoolPoint: _double(school?['lat']) == null || _double(school?['lng']) == null
          ? null
          : LatLngPoint(_double(school!['lat'])!, _double(school['lng'])!),
      vehiclePlate: _as<String>(vehicle?['plate']),
      pickupStartsAt: _as<String>(route?['pickup_starts_at']),
      dropoffStartsAt: _as<String>(route?['dropoff_starts_at']),
      startedAt: _date(json['started_at']),
      endedAt: _date(json['ended_at']),
      students: ((json['students'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(SchoolTripStudent.fromJson)
          .toList(),
    );
  }

  @override
  List<Object?> get props => [uuid, status, pickedUpCount, droppedOffCount, absentCount];
}

/// One child on one run: where to collect them, and whether that has happened.
class SchoolTripStudent extends Equatable {
  const SchoolTripStudent({
    required this.uuid,
    required this.sequence,
    required this.status,
    required this.statusLabel,
    required this.statusColor,
    required this.name,
    this.grade,
    this.pickupAddress,
    this.pickupPoint,
    this.medicalNotes,
    this.emergencyContactName,
    this.emergencyContactPhone,
    this.guardianPhone,
    this.pickedUpAt,
    this.droppedOffAt,
    this.note,
  });

  final String uuid;
  final int sequence;
  final String status;
  final String statusLabel;
  final String statusColor;
  final String name;
  final String? grade;
  final String? pickupAddress;
  final LatLngPoint? pickupPoint;
  final String? medicalNotes;
  final String? emergencyContactName;
  final String? emergencyContactPhone;
  final String? guardianPhone;
  final DateTime? pickedUpAt;
  final DateTime? droppedOffAt;
  final String? note;

  bool get isPending => status == 'pending';

  bool get isAboard => status == 'picked_up';

  /// Their journey is over, one way or another: home, or never boarded.
  bool get isSettled => status == 'dropped_off' || status == 'absent' || status == 'no_show';

  factory SchoolTripStudent.fromJson(Map<String, dynamic> json) {
    final student = _as<Map<String, dynamic>>(json['student']);

    return SchoolTripStudent(
      uuid: _as<String>(json['uuid']) ?? '',
      sequence: _int(json['sequence']) ?? 0,
      status: _as<String>(json['status']) ?? 'pending',
      statusLabel: _as<String>(json['status_label']) ?? '',
      statusColor: _as<String>(json['status_color']) ?? 'neutral',
      name: _as<String>(student?['name']) ?? '',
      grade: _as<String>(student?['grade']),
      pickupAddress: _as<String>(json['pickup_address']),
      pickupPoint: _double(json['pickup_lat']) == null || _double(json['pickup_lng']) == null
          ? null
          : LatLngPoint(_double(json['pickup_lat'])!, _double(json['pickup_lng'])!),
      medicalNotes: _as<String>(student?['medical_notes']),
      emergencyContactName: _as<String>(student?['emergency_contact_name']),
      emergencyContactPhone: _as<String>(student?['emergency_contact_phone']),
      guardianPhone: _as<String>(student?['guardian_phone']),
      pickedUpAt: _date(json['picked_up_at']),
      droppedOffAt: _date(json['dropped_off_at']),
      note: _as<String>(json['note']),
    );
  }

  @override
  List<Object?> get props => [uuid, status, sequence];
}

/// What a guardian is allowed to see while their child's run is under way.
///
/// Not a raw position: the useful answer is how far away, how long, and how
/// many doors before this one — all of it scoped to this one child, and only
/// while the child is actually on the journey.
class SchoolLiveView extends Equatable {
  const SchoolLiveView({
    required this.tripUuid,
    required this.status,
    required this.statusLabel,
    required this.direction,
    required this.directionLabel,
    required this.childStatus,
    required this.childStatusLabel,
    required this.stopsAhead,
    this.position,
    this.reportedAt,
    this.plate,
    this.model,
    this.color,
    this.driverName,
    this.driverPhone,
    this.distanceMeters,
    this.etaMinutes,
    this.isArriving = false,
    this.etaIsApproximate = true,
    this.startedAt,
    this.pickedUpAt,
    this.droppedOffAt,
  });

  final String tripUuid;
  final String status;
  final String statusLabel;
  final String direction;
  final String directionLabel;
  final String childStatus;
  final String childStatusLabel;
  final int stopsAhead;
  final LatLngPoint? position;
  final DateTime? reportedAt;
  final String? plate;
  final String? model;
  final String? color;
  final String? driverName;

  /// A parent standing on a pavement with a van that has not arrived needs to
  /// reach somebody.
  final String? driverPhone;
  final int? distanceMeters;
  final int? etaMinutes;
  final bool isArriving;

  /// Always true. A straight line is not a road, and saying so is the
  /// difference between a useful estimate and a broken promise.
  final bool etaIsApproximate;
  final DateTime? startedAt;
  final DateTime? pickedUpAt;
  final DateTime? droppedOffAt;

  bool get isAboard => childStatus == 'picked_up';

  factory SchoolLiveView.fromJson(Map<String, dynamic> json) {
    final vehicle = _as<Map<String, dynamic>>(json['vehicle']);
    final position = _as<Map<String, dynamic>>(json['position']);
    final eta = _as<Map<String, dynamic>>(json['eta']);

    return SchoolLiveView(
      tripUuid: _as<String>(json['trip_uuid']) ?? '',
      status: _as<String>(json['status']) ?? '',
      statusLabel: _as<String>(json['status_label']) ?? '',
      direction: _as<String>(json['direction']) ?? '',
      directionLabel: _as<String>(json['direction_label']) ?? '',
      childStatus: _as<String>(json['child_status']) ?? 'pending',
      childStatusLabel: _as<String>(json['child_status_label']) ?? '',
      stopsAhead: _int(json['stops_ahead']) ?? 0,
      position: position == null
          ? null
          : LatLngPoint(_double(position['lat']) ?? 0, _double(position['lng']) ?? 0),
      reportedAt: _date(position?['reported_at']),
      plate: _as<String>(vehicle?['plate']),
      model: _as<String>(vehicle?['model']),
      color: _as<String>(vehicle?['color']),
      driverName: _as<String>(json['driver_name']),
      driverPhone: _as<String>(json['driver_phone']),
      distanceMeters: _int(json['distance_meters']),
      etaMinutes: _int(eta?['minutes']),
      isArriving: eta?['is_arriving'] == true,
      etaIsApproximate: eta?['is_approximate'] != false,
      startedAt: _date(json['started_at']),
      pickedUpAt: _date(json['picked_up_at']),
      droppedOffAt: _date(json['dropped_off_at']),
    );
  }

  @override
  List<Object?> get props => [tripUuid, childStatus, position, distanceMeters, etaMinutes];
}

/// One day of a child's attendance, as the family's history shows it.
class SchoolAttendanceRow extends Equatable {
  const SchoolAttendanceRow({
    required this.uuid,
    required this.status,
    required this.statusLabel,
    this.serviceDate,
    this.direction,
    this.directionLabel,
    this.pickedUpAt,
    this.droppedOffAt,
    this.note,
  });

  final String uuid;
  final String status;
  final String statusLabel;
  final String? serviceDate;
  final String? direction;
  final String? directionLabel;
  final DateTime? pickedUpAt;
  final DateTime? droppedOffAt;
  final String? note;

  factory SchoolAttendanceRow.fromJson(Map<String, dynamic> json) => SchoolAttendanceRow(
        uuid: _as<String>(json['uuid']) ?? '',
        status: _as<String>(json['status']) ?? 'pending',
        statusLabel: _as<String>(json['status_label']) ?? '',
        serviceDate: _as<String>(json['service_date']),
        direction: _as<String>(json['direction']),
        directionLabel: _as<String>(json['direction_label']),
        pickedUpAt: _date(json['picked_up_at']),
        droppedOffAt: _date(json['dropped_off_at']),
        note: _as<String>(json['note']),
      );

  @override
  List<Object?> get props => [uuid, status];
}
