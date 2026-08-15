import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// Device location.
///
/// Permission is requested lazily, at the moment a feature needs it, and a
/// refusal is a normal outcome rather than an error: every screen that uses
/// this falls back to the city centre.
final devicePositionProvider = FutureProvider<Position?>((ref) async {
  if (!await Geolocator.isLocationServiceEnabled()) return null;

  var permission = await Geolocator.checkPermission();

  if (permission == LocationPermission.denied) {
    permission = await Geolocator.requestPermission();
  }

  if (permission == LocationPermission.denied ||
      permission == LocationPermission.deniedForever) {
    return null;
  }

  try {
    return await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 12),
      ),
    );
  } catch (_) {
    // A timeout indoors is common; the last known fix is better than nothing.
    return Geolocator.getLastKnownPosition();
  }
});

/// Live buses for the city.
///
/// Deliberately dual-sourced: the WebSocket delivers updates as they happen,
/// and a slow timer re-fetches regardless. If the socket is blocked the map
/// still moves, just less often — a frozen map with no explanation is the one
/// outcome worth engineering against.
class LiveBusesNotifier extends StateNotifier<AsyncValue<List<LiveBus>>> {
  LiveBusesNotifier(this._ref) : super(const AsyncValue.loading()) {
    unawaited(refresh());
    _startPolling();
  }

  final Ref _ref;
  Timer? _timer;

  Future<void> refresh() async {
    try {
      final buses = await _ref.read(transitApiProvider).liveBuses();
      if (mounted) state = AsyncValue.data(buses);
    } catch (error, stack) {
      // Keep showing the last known positions rather than emptying the map.
      if (mounted && state is! AsyncData) state = AsyncValue.error(error, stack);
    }
  }

  void _startPolling() {
    _timer = Timer.periodic(const Duration(seconds: 12), (_) => refresh());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}

final liveBusesProvider =
    StateNotifierProvider<LiveBusesNotifier, AsyncValue<List<LiveBus>>>(LiveBusesNotifier.new);

/// Stops near the device, falling back to the whole city list when there is
/// no position available.
final nearbyStopsProvider = FutureProvider<List<BusStop>>((ref) async {
  final api = ref.watch(transitApiProvider);
  final position = await ref.watch(devicePositionProvider.future);

  if (position == null) {
    return api.stops(limit: 40);
  }

  final nearby = await api.stops(
    lat: position.latitude,
    lng: position.longitude,
    radius: 1500,
    limit: 25,
  );

  return nearby.isEmpty ? api.stops(limit: 40) : nearby;
});

/// The stop the arrival board is showing. Null means "closest to me".
final selectedStopProvider = StateProvider<BusStop?>((ref) => null);

final arrivalsProvider = FutureProvider.autoDispose<List<Arrival>>((ref) async {
  final stop = ref.watch(selectedStopProvider) ??
      (await ref.watch(nearbyStopsProvider.future)).firstOrNull;

  if (stop == null) return const [];

  // Boards go stale within seconds, so this refreshes on its own cadence
  // rather than waiting for the user to pull.
  final timer = Timer(const Duration(seconds: 20), () => ref.invalidateSelf());
  ref.onDispose(timer.cancel);

  return ref.watch(transitApiProvider).arrivals(stop.id);
});

final walletProvider = FutureProvider<Wallet>(
  (ref) => ref.watch(transitApiProvider).wallet(),
);

final walletTransactionsProvider = FutureProvider<List<LedgerEntry>>(
  (ref) => ref.watch(transitApiProvider).walletTransactions(),
);

final activeRideProvider = FutureProvider<ActiveRide?>(
  (ref) => ref.watch(transitApiProvider).activeRide(),
);

final rideHistoryProvider = FutureProvider<List<RideHistoryItem>>(
  (ref) => ref.watch(transitApiProvider).rideHistory(),
);

final complaintsProvider = FutureProvider<List<Complaint>>(
  (ref) => ref.watch(transitApiProvider).complaints(),
);

final linesProvider = FutureProvider<List<BusLine>>(
  (ref) => ref.watch(transitApiProvider).lines(),
);
