import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import 'location_service.dart';

/// Today's runs, in the order they happen.
final schoolStateProvider = FutureProvider<SchoolDriverState>((ref) async {
  final data = await ref.watch(transitApiProvider).schoolDriverState();

  return SchoolDriverState.fromJson(data);
});

/// One run with its manifest.
///
/// Polled while the screen is open so that an absence a parent reported from
/// their own app appears before the driver reaches the door — that is the
/// whole value of knowing early.
final schoolTripProvider = FutureProvider.autoDispose.family<SchoolTrip, String>((ref, uuid) async {
  final timer = Timer(const Duration(seconds: 20), ref.invalidateSelf);
  ref.onDispose(timer.cancel);

  return ref.watch(transitApiProvider).schoolTrip(uuid);
});

final schoolLocationServiceProvider = Provider<SchoolLocationService>((ref) {
  final service = SchoolLocationService(ref.watch(transitApiProvider));
  ref.onDispose(service.dispose);

  return service;
});

final schoolLocationStatusProvider = StreamProvider<SchoolReportStatus>(
  (ref) => ref.watch(schoolLocationServiceProvider).status,
);

class SchoolDriverState {
  const SchoolDriverState({
    required this.name,
    required this.date,
    required this.trips,
    this.blocker,
    this.liveTripUuid,
  });

  final String name;
  final String date;
  final List<SchoolTrip> trips;

  /// Why this driver may not take a van out, answered before they try.
  final String? blocker;
  final String? liveTripUuid;

  bool get mayDrive => blocker == null;

  factory SchoolDriverState.fromJson(Map<String, dynamic> json) {
    final driver = (json['driver'] as Map<String, dynamic>?) ?? const {};

    return SchoolDriverState(
      name: driver['name'] as String? ?? Format.tr('school.fallback_name'),
      blocker: driver['blocker'] as String?,
      date: json['date'] as String? ?? '',
      liveTripUuid: json['live_trip'] as String?,
      trips: ((json['trips'] as List<dynamic>?) ?? const [])
          .whereType<Map<String, dynamic>>()
          .map(SchoolTrip.fromJson)
          .toList(),
    );
  }
}
