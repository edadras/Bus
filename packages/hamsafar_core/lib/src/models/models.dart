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
  });

  final String tileUrl;
  final String attribution;
  final int maxZoom;
  final LatLngPoint center;
  final double zoom;

  static const fallback = MapConfig(
    tileUrl: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    attribution: '© OpenStreetMap contributors',
    maxZoom: 19,
    center: LatLngPoint(27.1832, 56.2666),
    zoom: 13,
  );

  factory MapConfig.fromJson(Map<String, dynamic> json) {
    final provider = _as<Map<String, dynamic>>(json['provider']) ?? const {};

    return MapConfig(
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
