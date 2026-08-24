import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import 'location_service.dart';

/// Everything the app needs on launch: the driver, the cars they may drive,
/// the open shift and the code passengers are scanning right now.
final taxiStateProvider = FutureProvider<TaxiDriverState>((ref) async {
  final data = await ref.watch(transitApiProvider).taxiDriverState();

  return TaxiDriverState.fromJson(data);
});

final taxiLinesProvider = FutureProvider<List<TaxiLine>>(
  (ref) => ref.watch(transitApiProvider).taxiDriverLines(),
);

/// Who is aboard and what has been taken, polled while the screen is open.
///
/// A line driver watches this the way a shopkeeper watches a till: the whole
/// point of the fixed-fare mode is that money arrives without a conversation,
/// so it has to arrive visibly.
final taxiRidesProvider = FutureProvider.autoDispose<TaxiRidesView>((ref) async {
  final timer = Timer(const Duration(seconds: 8), ref.invalidateSelf);
  ref.onDispose(timer.cancel);

  return TaxiRidesView.fromJson(await ref.watch(transitApiProvider).taxiDriverRides());
});

final taxiEarningsProvider = FutureProvider.autoDispose<TaxiEarnings>((ref) async {
  return TaxiEarnings.fromJson(await ref.watch(transitApiProvider).taxiEarnings());
});

final taxiSettlementsProvider =
    FutureProvider.autoDispose<({List<TaxiSettlement> items, int pending})>(
  (ref) => ref.watch(transitApiProvider).taxiSettlements(),
);

/// The position reporter, held for the whole app session so it survives tab
/// switches — a shift must not stop reporting because the driver looked at
/// their earnings.
final taxiLocationServiceProvider = Provider<TaxiLocationService>((ref) {
  final service = TaxiLocationService(ref.watch(transitApiProvider));
  ref.onDispose(service.dispose);

  return service;
});

final taxiLocationStatusProvider = StreamProvider<TaxiReportStatus>(
  (ref) => ref.watch(taxiLocationServiceProvider).status,
);

/// The running meter, as the car's own reports have advanced it.
final taxiMeterProvider = StreamProvider<TaxiRide?>(
  (ref) => ref.watch(taxiLocationServiceProvider).meter,
);

// ── state ──────────────────────────────────────────────────────────────────

class TaxiDriverState {
  const TaxiDriverState({
    required this.name,
    required this.status,
    required this.assignedTaxis,
    required this.pendingSettlement,
    this.blocker,
    this.licenseExpiresAt,
    this.shift,
    this.qr,
  });

  final String name;
  final String status;
  final List<AssignedTaxi> assignedTaxis;

  /// Money already earned and not yet asked for. Shown on the home screen
  /// because it is the number a driver checks before deciding to stop.
  final int pendingSettlement;

  /// The reason a shift would be refused, answered before they try rather than
  /// as an error after.
  final String? blocker;
  final DateTime? licenseExpiresAt;
  final TaxiShift? shift;
  final TaxiQrToken? qr;

  bool get hasOpenShift => shift != null;

  bool get mayDrive => blocker == null;

  /// What the car on shift is licensed to offer.
  ///
  /// Falls back to all three rather than to none: an empty list would leave a
  /// driver with a car and no way to say what they are doing with it, and the
  /// server refuses an unlicensed mode anyway.
  List<TaxiServiceType> get allowedModes {
    if (shift == null) return const [];

    for (final taxi in assignedTaxis) {
      if (taxi.uuid == shift!.taxiUuid && taxi.allowedModes.isNotEmpty) {
        return taxi.allowedModes;
      }
    }

    return TaxiServiceType.values;
  }

  factory TaxiDriverState.fromJson(Map<String, dynamic> json) {
    final driver = (json['driver'] as Map<String, dynamic>?) ?? const {};
    final shift = json['shift'] as Map<String, dynamic>?;
    final qr = json['qr'] as Map<String, dynamic>?;

    return TaxiDriverState(
      name: driver['name'] as String? ?? Format.tr('taxi.fallback_name'),
      status: driver['status'] as String? ?? 'unknown',
      blocker: driver['blocker'] as String?,
      licenseExpiresAt: driver['license_expires_at'] == null
          ? null
          : DateTime.tryParse(driver['license_expires_at'] as String),
      assignedTaxis: ((json['assigned_taxis'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(AssignedTaxi.fromJson)
          .toList(),
      pendingSettlement: (json['pending_settlement'] as num?)?.toInt() ?? 0,
      shift: shift == null ? null : TaxiShift.fromJson(shift),
      qr: qr == null ? null : TaxiQrToken.fromJson(qr),
    );
  }
}

class AssignedTaxi {
  const AssignedTaxi({
    required this.uuid,
    required this.number,
    required this.allowedModes,
    this.plate,
    this.model,
    this.color,
    this.capacity,
    this.defaultLineId,
    this.defaultLineLabel,
  });

  final String uuid;
  final String number;

  /// What this car is licensed to run. A driver cannot offer a mode the
  /// vehicle is not permitted, so the picker is built from this rather than
  /// from the three products in the abstract.
  final List<TaxiServiceType> allowedModes;
  final String? plate;
  final String? model;
  final String? color;
  final int? capacity;
  final int? defaultLineId;
  final String? defaultLineLabel;

  factory AssignedTaxi.fromJson(Map<String, dynamic> json) {
    final taxi = (json['taxi'] as Map<String, dynamic>?) ?? json;
    final line = taxi['default_line'] as Map<String, dynamic>?;

    return AssignedTaxi(
      uuid: taxi['uuid'] as String? ?? '',
      number: taxi['taxi_number']?.toString() ?? '—',
      allowedModes: ((taxi['allowed_modes'] as List<dynamic>?) ?? const [])
          .map((mode) => TaxiServiceType.from(mode as String?))
          .toList(),
      plate: taxi['plate'] as String?,
      model: taxi['model'] as String?,
      color: taxi['color'] as String?,
      capacity: (taxi['capacity'] as num?)?.toInt(),
      defaultLineId: (line?['id'] as num?)?.toInt(),
      defaultLineLabel: line == null ? null : '${line['code']} — ${line['name']}',
    );
  }
}

/// The driver's open shift: what the car is offering, and what it has taken.
class TaxiShift {
  const TaxiShift({
    required this.uuid,
    required this.serviceType,
    required this.rideCount,
    required this.boardingCount,
    required this.alightingCount,
    required this.onboardCount,
    required this.grossMinor,
    required this.netMinor,
    required this.formattedGross,
    required this.formattedNet,
    required this.durationMinutes,
    this.lineId,
    this.lineCode,
    this.lineName,
    this.lineFare,
    this.formattedLineFare,
    this.pendingCharterAmount,
    this.taxiNumber,
    this.taxiUuid,
    this.startedAt,
  });

  final String uuid;
  final TaxiServiceType serviceType;
  final int rideCount;
  final int boardingCount;
  final int alightingCount;
  final int onboardCount;
  final int grossMinor;
  final int netMinor;
  final String formattedGross;
  final String formattedNet;
  final int durationMinutes;
  final int? lineId;
  final String? lineCode;
  final String? lineName;
  final int? lineFare;
  final String? formattedLineFare;

  /// Null once the price has been taken or has gone stale, which is the same
  /// thing to the driver: no price is standing right now.
  final int? pendingCharterAmount;
  final String? taxiNumber;
  final String? taxiUuid;
  final DateTime? startedAt;

  factory TaxiShift.fromJson(Map<String, dynamic> json) {
    final line = json['line'] as Map<String, dynamic>?;
    final taxi = json['taxi'] as Map<String, dynamic>?;

    return TaxiShift(
      uuid: json['uuid'] as String? ?? '',
      serviceType: TaxiServiceType.from(json['service_type'] as String?),
      rideCount: (json['ride_count'] as num?)?.toInt() ?? 0,
      boardingCount: (json['boarding_count'] as num?)?.toInt() ?? 0,
      alightingCount: (json['alighting_count'] as num?)?.toInt() ?? 0,
      onboardCount: (json['onboard_count'] as num?)?.toInt() ?? 0,
      grossMinor: (json['gross_minor'] as num?)?.toInt() ?? 0,
      netMinor: (json['net_minor'] as num?)?.toInt() ?? 0,
      formattedGross: json['formatted_gross'] as String? ?? '—',
      formattedNet: json['formatted_net'] as String? ?? '—',
      durationMinutes: (json['duration_minutes'] as num?)?.toInt() ?? 0,
      lineId: (line?['id'] as num?)?.toInt(),
      lineCode: line?['code']?.toString(),
      lineName: line?['name'] as String?,
      lineFare: (line?['flat_fare'] as num?)?.toInt(),
      formattedLineFare: line?['formatted_fare'] as String?,
      pendingCharterAmount: (json['pending_charter_amount'] as num?)?.toInt(),
      taxiNumber: taxi?['taxi_number']?.toString(),
      taxiUuid: taxi?['uuid'] as String?,
      startedAt: json['started_at'] == null
          ? null
          : DateTime.tryParse(json['started_at'] as String),
    );
  }
}

/// The rotating code on the driver's screen.
///
/// It is not a taxi identifier: it carries a time step and a single-use nonce,
/// so a photograph of the screen is worthless a minute later.
class TaxiQrToken {
  const TaxiQrToken({
    required this.token,
    required this.publicId,
    required this.expiresIn,
    required this.rotationSeconds,
  });

  final String token;
  final String publicId;
  final int expiresIn;
  final int rotationSeconds;

  factory TaxiQrToken.fromJson(Map<String, dynamic> json) => TaxiQrToken(
        token: json['token'] as String? ?? '',
        publicId: json['public_id'] as String? ?? '',
        expiresIn: (json['expires_in'] as num?)?.toInt() ?? 30,
        rotationSeconds: (json['rotation_seconds'] as num?)?.toInt() ?? 30,
      );
}

class TaxiRidesView {
  const TaxiRidesView({required this.shift, required this.onboard, required this.recent});

  final TaxiShift? shift;
  final List<TaxiRide> onboard;
  final List<TaxiRide> recent;

  factory TaxiRidesView.fromJson(Map<String, dynamic> json) {
    final shift = json['shift'] as Map<String, dynamic>?;

    return TaxiRidesView(
      shift: shift == null ? null : TaxiShift.fromJson(shift),
      onboard: ((json['onboard'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(TaxiRide.fromJson)
          .toList(),
      recent: ((json['recent'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(TaxiRide.fromJson)
          .toList(),
    );
  }
}

class TaxiEarnings {
  const TaxiEarnings({
    required this.rideCount,
    required this.gross,
    required this.commission,
    required this.net,
    required this.distanceMeters,
    required this.unpaidCount,
    required this.unpaidAmount,
    required this.byDay,
    required this.byMode,
  });

  final int rideCount;
  final int gross;
  final int commission;
  final int net;
  final int distanceMeters;

  /// Shown apart from the totals rather than folded into them. A fare the
  /// passenger never paid is not earnings, and hiding it would make a driver's
  /// own figures disagree with their payout.
  final int unpaidCount;
  final int unpaidAmount;
  final List<EarningsDay> byDay;
  final List<EarningsMode> byMode;

  factory TaxiEarnings.fromJson(Map<String, dynamic> json) => TaxiEarnings(
        rideCount: (json['ride_count'] as num?)?.toInt() ?? 0,
        gross: (json['gross'] as num?)?.toInt() ?? 0,
        commission: (json['commission'] as num?)?.toInt() ?? 0,
        net: (json['net'] as num?)?.toInt() ?? 0,
        distanceMeters: (json['distance_meters'] as num?)?.toInt() ?? 0,
        unpaidCount: (json['unpaid_count'] as num?)?.toInt() ?? 0,
        unpaidAmount: (json['unpaid_amount'] as num?)?.toInt() ?? 0,
        byDay: ((json['by_day'] as List<dynamic>?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(EarningsDay.fromJson)
            .toList(),
        byMode: ((json['by_mode'] as List<dynamic>?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(EarningsMode.fromJson)
            .toList(),
      );
}

class EarningsDay {
  const EarningsDay({
    required this.date,
    required this.rideCount,
    required this.gross,
    required this.net,
  });

  final String date;
  final int rideCount;
  final int gross;
  final int net;

  factory EarningsDay.fromJson(Map<String, dynamic> json) => EarningsDay(
        date: json['date'] as String? ?? '',
        rideCount: (json['ride_count'] as num?)?.toInt() ?? 0,
        gross: (json['gross'] as num?)?.toInt() ?? 0,
        net: (json['net'] as num?)?.toInt() ?? 0,
      );
}

class EarningsMode {
  const EarningsMode({
    required this.serviceType,
    required this.label,
    required this.rideCount,
    required this.gross,
  });

  final TaxiServiceType serviceType;
  final String label;
  final int rideCount;
  final int gross;

  factory EarningsMode.fromJson(Map<String, dynamic> json) => EarningsMode(
        serviceType: TaxiServiceType.from(json['service_type'] as String?),
        label: json['label'] as String? ?? '',
        rideCount: (json['ride_count'] as num?)?.toInt() ?? 0,
        gross: (json['gross'] as num?)?.toInt() ?? 0,
      );
}
