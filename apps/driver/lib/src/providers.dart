import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import 'location_service.dart';

/// Everything the driver app needs on launch: the driver record, assigned
/// buses, the open shift and any live trip.
final driverStateProvider = FutureProvider<DriverState>((ref) async {
  final data = await ref.watch(transitApiProvider).driverState();

  return DriverState.fromJson(data);
});

class DriverState {
  const DriverState({
    required this.name,
    required this.status,
    required this.totalTrips,
    required this.assignedBuses,
    this.employeeCode,
    this.licenseExpiresAt,
    this.shift,
    this.trip,
  });

  final String name;
  final String status;
  final int totalTrips;
  final List<AssignedBus> assignedBuses;
  final String? employeeCode;
  final DateTime? licenseExpiresAt;
  final ShiftSummary? shift;
  final TripSummary? trip;

  bool get hasOpenShift => shift != null && shift!.status == 'open';

  bool get hasLiveTrip => trip != null;

  /// A licence expiring inside a month is worth warning about before it stops
  /// the driver mid-week.
  bool get licenseExpiringSoon =>
      licenseExpiresAt != null && licenseExpiresAt!.difference(DateTime.now()).inDays <= 30;

  factory DriverState.fromJson(Map<String, dynamic> json) {
    final driver = (json['driver'] as Map<String, dynamic>?) ?? const {};

    return DriverState(
      name: driver['name'] as String? ?? Format.tr('driver.fallback_name'),
      status: driver['status'] as String? ?? 'unknown',
      totalTrips: (driver['total_trips'] as num?)?.toInt() ?? 0,
      employeeCode: driver['employee_code'] as String?,
      licenseExpiresAt: driver['license_expires_at'] == null
          ? null
          : DateTime.tryParse(driver['license_expires_at'] as String),
      assignedBuses: ((json['assigned_buses'] as List<dynamic>?) ?? const [])
          .cast<Map<String, dynamic>>()
          .map(AssignedBus.fromJson)
          .toList(),
      shift: json['shift'] == null
          ? null
          : ShiftSummary.fromJson(json['shift'] as Map<String, dynamic>),
      trip:
          json['trip'] == null ? null : TripSummary.fromJson(json['trip'] as Map<String, dynamic>),
    );
  }
}

class AssignedBus {
  const AssignedBus({required this.uuid, required this.number, this.lineCode, this.lineName});

  final String uuid;
  final String number;
  final String? lineCode;
  final String? lineName;

  factory AssignedBus.fromJson(Map<String, dynamic> json) {
    final bus = (json['bus'] as Map<String, dynamic>?) ?? const {};
    final line = json['line'] as Map<String, dynamic>?;

    return AssignedBus(
      uuid: bus['uuid'] as String? ?? '',
      number: bus['bus_number']?.toString() ?? '—',
      lineCode: line?['code']?.toString(),
      lineName: line?['name'] as String?,
    );
  }
}

class ShiftSummary {
  const ShiftSummary({
    required this.id,
    required this.status,
    required this.tripCount,
    required this.passengerCount,
    required this.formattedRevenue,
    required this.durationMinutes,
    required this.distanceMeters,
    this.busNumber,
    this.startedAt,
  });

  final int id;
  final String status;
  final int tripCount;
  final int passengerCount;
  final String formattedRevenue;
  final int durationMinutes;
  final int distanceMeters;
  final String? busNumber;
  final DateTime? startedAt;

  factory ShiftSummary.fromJson(Map<String, dynamic> json) => ShiftSummary(
        id: (json['id'] as num?)?.toInt() ?? 0,
        status: json['status'] as String? ?? 'open',
        tripCount: (json['trip_count'] as num?)?.toInt() ?? 0,
        passengerCount: (json['passenger_count'] as num?)?.toInt() ?? 0,
        formattedRevenue:
            (json['revenue'] as Map<String, dynamic>?)?['formatted'] as String? ?? '—',
        durationMinutes: (json['duration_minutes'] as num?)?.toInt() ?? 0,
        distanceMeters: (json['distance_meters'] as num?)?.toInt() ?? 0,
        busNumber: (json['bus'] as Map<String, dynamic>?)?['number']?.toString(),
        startedAt:
            json['started_at'] == null ? null : DateTime.tryParse(json['started_at'] as String),
      );
}

class TripSummary {
  const TripSummary({
    required this.uuid,
    required this.status,
    required this.passengerCount,
    this.lineCode,
    this.lineName,
    this.destination,
    this.nextStop,
    this.nextStopEtaSeconds,
    this.nextStopDistance,
    this.distanceMeters = 0,
    this.isOffRoute = false,
    this.isIdle = false,
  });

  final String uuid;
  final String status;
  final int passengerCount;
  final String? lineCode;
  final String? lineName;
  final String? destination;
  final String? nextStop;
  final int? nextStopEtaSeconds;
  final int? nextStopDistance;
  final int distanceMeters;
  final bool isOffRoute;
  final bool isIdle;

  bool get isPaused => status == 'paused';

  factory TripSummary.fromJson(Map<String, dynamic> json) {
    final line = json['line'] as Map<String, dynamic>?;
    final nextStop = json['next_stop'] as Map<String, dynamic>?;

    return TripSummary(
      uuid: json['uuid'] as String? ?? '',
      status: json['status'] as String? ?? 'active',
      passengerCount: (json['passenger_count'] as num?)?.toInt() ?? 0,
      lineCode: line?['code']?.toString(),
      lineName: line?['name'] as String?,
      destination: json['destination'] as String?,
      nextStop: nextStop?['name'] as String?,
      nextStopEtaSeconds: (nextStop?['eta_seconds'] as num?)?.toInt(),
      nextStopDistance: (nextStop?['distance_meters'] as num?)?.toInt(),
      distanceMeters: (json['distance_meters'] as num?)?.toInt() ?? 0,
      isOffRoute: json['is_off_route'] == true,
      isIdle: json['is_idle'] == true,
    );
  }
}

/// The GPS reporter, kept alive for the whole app session so it survives tab
/// switches — a shift must not stop reporting because the driver looked at the
/// route screen.
final locationServiceProvider = Provider<DriverLocationService>((ref) {
  final service = DriverLocationService(ref.watch(transitApiProvider));
  ref.onDispose(service.dispose);

  return service;
});

final locationStatusProvider = StreamProvider<LocationReportStatus>(
  (ref) => ref.watch(locationServiceProvider).status,
);

/// Live passenger figures for the current trip. Polled frequently because this
/// is the number the driver is actually watching.
final passengersProvider = FutureProvider.autoDispose<Map<String, dynamic>>((ref) async {
  final timer = Timer(const Duration(seconds: 8), () => ref.invalidateSelf());
  ref.onDispose(timer.cancel);

  return ref.watch(transitApiProvider).driverPassengers();
});

final driverRouteProvider = FutureProvider.autoDispose<Map<String, dynamic>>(
  (ref) => ref.watch(transitApiProvider).driverRoute(),
);
