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

  if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
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
  final stop =
      ref.watch(selectedStopProvider) ?? (await ref.watch(nearbyStopsProvider.future)).firstOrNull;

  if (stop == null) return const [];

  // Boards go stale within seconds, so this refreshes on its own cadence
  // rather than waiting for the user to pull.
  final timer = Timer(const Duration(seconds: 20), () => ref.invalidateSelf());
  ref.onDispose(timer.cancel);

  return ref.watch(transitApiProvider).arrivals(stop.id);
});

/// When one live bus reaches one stop.
///
/// Auto-disposed and keyed by the pair, so closing the sheet stops the work and
/// two buses watched in turn do not read each other's estimate.
final stopEtaProvider =
    FutureProvider.autoDispose.family<StopEta?, ({String tripUuid, int stopId})>(
  (ref, key) => ref.watch(transitApiProvider).tripEta(key.tripUuid, key.stopId),
);

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

/// One end of a planned journey: either a stop the passenger picked, or the
/// device itself. The device position is deliberately *not* captured here — it
/// is read at search time, so a fix taken while the screen was open is never
/// what gets planned from.
class JourneyEndpoint {
  const JourneyEndpoint.device() : stop = null;

  const JourneyEndpoint.stop(BusStop this.stop);

  final BusStop? stop;

  bool get isDevice => stop == null;

  String get label => stop?.name ?? Format.tr('plan.my_location');
}

final journeyOriginProvider = StateProvider<JourneyEndpoint?>(
  (ref) => const JourneyEndpoint.device(),
);

final journeyDestinationProvider = StateProvider<JourneyEndpoint?>((ref) => null);

/// How many changes the passenger will accept. The server caps this at two.
final journeyMaxTransfersProvider = StateProvider<int>((ref) => 2);

/// Stops matching what the passenger typed into the endpoint picker. An empty
/// query falls back to the stops near them, which is the useful default.
final stopSearchProvider = FutureProvider.autoDispose.family<List<BusStop>, String>(
  (ref, query) async {
    final trimmed = query.trim();

    if (trimmed.isEmpty) return ref.watch(nearbyStopsProvider.future);

    return ref.watch(transitApiProvider).stops(query: trimmed, limit: 25);
  },
);

/// Journey planning is a request, not a subscription: it runs when the
/// passenger asks for it and the answer stays until they ask again. Null means
/// "nothing asked yet", which the screen shows differently from "no results".
class JourneyPlanNotifier extends StateNotifier<AsyncValue<JourneyPlan>?> {
  JourneyPlanNotifier(this._ref) : super(null);

  final Ref _ref;

  Future<void> search() async {
    final origin = _ref.read(journeyOriginProvider);
    final destination = _ref.read(journeyDestinationProvider);

    if (origin == null || destination == null) return;

    state = const AsyncValue.loading();

    try {
      final from = await _resolve(origin);
      final to = await _resolve(destination);

      final plan = await _ref.read(transitApiProvider).planJourney(
            fromLat: from.lat,
            fromLng: from.lng,
            toLat: to.lat,
            toLng: to.lng,
            maxTransfers: _ref.read(journeyMaxTransfersProvider),
          );

      if (mounted) state = AsyncValue.data(plan);
    } catch (error, stack) {
      if (mounted) state = AsyncValue.error(error, stack);
    }
  }

  void clear() {
    if (mounted) state = null;
  }

  Future<LatLngPoint> _resolve(JourneyEndpoint endpoint) async {
    if (endpoint.stop != null) return endpoint.stop!.position;

    final position = await _ref.read(devicePositionProvider.future);

    if (position == null) {
      // Carried as an ApiException so the screen has one error branch: the
      // code is what it reads, and this one never came from the server.
      throw ApiException(
        code: 'location_unavailable',
        message: Format.tr('plan.location_unavailable'),
      );
    }

    return LatLngPoint(position.latitude, position.longitude);
  }
}

final journeyPlanProvider =
    StateNotifierProvider<JourneyPlanNotifier, AsyncValue<JourneyPlan>?>(JourneyPlanNotifier.new);

/// The in-app notification inbox.
///
/// State is held rather than re-fetched on every read so marking one item read
/// updates the badge instantly — a list that only settles after a round trip
/// feels broken on a bus with two bars of signal.
class NotificationsNotifier extends StateNotifier<AsyncValue<List<AppNotification>>> {
  NotificationsNotifier(this._ref) : super(const AsyncValue.loading()) {
    unawaited(refresh());
  }

  final Ref _ref;
  int _unreadCount = 0;

  int get unreadCount => _unreadCount;

  Future<void> refresh() async {
    try {
      final result = await _ref.read(transitApiProvider).notifications();

      if (!mounted) return;

      _unreadCount = result.unreadCount;
      state = AsyncValue.data(result.items);
    } catch (error, stack) {
      if (mounted) state = AsyncValue.error(error, stack);
    }
  }

  Future<void> markRead(AppNotification notification) async {
    if (notification.read) return;

    // Optimistic: the badge drops immediately, and a failed call is corrected
    // by the next refresh.
    state = state.whenData(
      (items) => items
          .map((item) => item.id == notification.id ? item.copyWith(read: true) : item)
          .toList(),
    );
    _unreadCount = (_unreadCount - 1).clamp(0, 1 << 30);

    try {
      await _ref.read(transitApiProvider).markNotificationRead(notification.id);
    } catch (_) {
      await refresh();
    }
  }

  Future<void> markAllRead() async {
    state = state.whenData((items) => items.map((item) => item.copyWith(read: true)).toList());
    _unreadCount = 0;

    try {
      await _ref.read(transitApiProvider).markAllNotificationsRead();
    } catch (_) {
      await refresh();
    }
  }
}

final notificationsProvider =
    StateNotifierProvider<NotificationsNotifier, AsyncValue<List<AppNotification>>>(
  NotificationsNotifier.new,
);

// ── Taxis ──────────────────────────────────────────────────────────────────

/// Whether the taxi layer is drawn on the map. Off by default: a rider looking
/// for a bus should not have to pick their line out of a screen of cars.
final showTaxisProvider = StateProvider<bool>((ref) => false);

/// Which of the three products the rider is looking for, or null for all.
final taxiModeFilterProvider = StateProvider<TaxiServiceType?>((ref) => null);

/// Taxis near the device.
///
/// A position is required rather than optional: this is a "near me" feed, and
/// a city-wide list of every taxi would be a tracking service for the people
/// driving them. With no fix there is nothing to show, which the map says.
final nearbyTaxisProvider = FutureProvider.autoDispose<List<NearbyTaxi>>((ref) async {
  final position = await ref.watch(devicePositionProvider.future);

  if (position == null) return const [];

  final timer = Timer(const Duration(seconds: 12), ref.invalidateSelf);
  ref.onDispose(timer.cancel);

  return ref.watch(transitApiProvider).nearbyTaxis(
        lat: position.latitude,
        lng: position.longitude,
        serviceType: ref.watch(taxiModeFilterProvider)?.value,
      );
});

/// The ride the passenger is on.
///
/// Polled quickly while a meter runs: the fare is something the passenger
/// watches, and a number that only appears at the destination is a surprise
/// rather than a price.
final activeTaxiRideProvider = FutureProvider<TaxiRide?>((ref) async {
  final ride = await ref.watch(transitApiProvider).activeTaxiRide();

  if (ride != null && ride.serviceType.isOpenEnded) {
    final timer = Timer(const Duration(seconds: 10), ref.invalidateSelf);
    ref.onDispose(timer.cancel);
  }

  return ride;
});

final taxiHistoryProvider =
    FutureProvider<({List<TaxiRide> items, int outstanding})>(
  (ref) => ref.watch(transitApiProvider).taxiRideHistory(),
);

// ── School service ─────────────────────────────────────────────────────────

/// The companies a parent may choose between: approved ones, and no others.
final schoolCompaniesProvider = FutureProvider<List<SchoolCompany>>(
  (ref) => ref.watch(transitApiProvider).schoolCompanies(),
);

final schoolsListProvider = FutureProvider<List<SchoolSummary>>(
  (ref) => ref.watch(transitApiProvider).schoolsList(),
);

final schoolStudentsProvider = FutureProvider<List<SchoolStudent>>(
  (ref) => ref.watch(transitApiProvider).schoolStudents(),
);

final schoolContractsProvider = FutureProvider<List<SchoolContract>>(
  (ref) => ref.watch(transitApiProvider).schoolContracts(),
);

final schoolInvoicesProvider = FutureProvider<List<SchoolInvoice>>(
  (ref) => ref.watch(transitApiProvider).schoolInvoices(),
);

/// Where one child's van is, if a run is under way.
///
/// A null view with a reason is the ordinary answer for most of the day, not
/// an error: there is usually no van to watch, and the screen says so rather
/// than showing a failure.
final schoolLiveProvider =
    FutureProvider.autoDispose.family<({SchoolLiveView? view, String? reason}), String>(
  (ref, studentUuid) async {
    final timer = Timer(const Duration(seconds: 15), ref.invalidateSelf);
    ref.onDispose(timer.cancel);

    return ref.watch(transitApiProvider).schoolStudentLive(studentUuid);
  },
);

final schoolAttendanceProvider =
    FutureProvider.autoDispose.family<List<SchoolAttendanceRow>, String>(
  (ref, studentUuid) => ref.watch(transitApiProvider).schoolStudentAttendance(studentUuid),
);
