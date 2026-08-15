import 'dart:async';

import 'package:geolocator/geolocator.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

/// Continuous position reporting for a bus in service.
///
/// Two things make this different from a naive location loop:
///
///  - The reporting cadence is dictated by the server. Each accepted ping
///    comes back with `next_ping_in`, which is short while approaching a stop
///    and long while idling at a terminal. Battery cost is therefore tuned
///    centrally rather than guessed by every app version in the field.
///
///  - Failures are expected. Buses drive through tunnels and dead spots, so a
///    rejected or unreachable ping is retried on the next tick rather than
///    surfaced as an error; only a sustained outage is reported to the driver.
class DriverLocationService {
  DriverLocationService(this._api);

  final TransitApi _api;

  StreamSubscription<Position>? _positionSubscription;
  Timer? _reportTimer;
  Position? _latest;

  int _intervalSeconds = 5;
  int _consecutiveFailures = 0;
  bool _running = false;

  final _statusController = StreamController<LocationReportStatus>.broadcast();

  Stream<LocationReportStatus> get status => _statusController.stream;

  bool get isRunning => _running;

  int get intervalSeconds => _intervalSeconds;

  /// Ask for the permissions a shift needs. Returns the reason it cannot start,
  /// or null when everything required has been granted.
  static Future<String?> ensurePermissions() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return 'سرویس موقعیت مکانی دستگاه خاموش است.';
    }

    var permission = await Geolocator.checkPermission();

    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }

    return switch (permission) {
      LocationPermission.denied => 'برای شروع شیفت باید دسترسی موقعیت مکانی را بدهید.',
      LocationPermission.deniedForever =>
        'دسترسی موقعیت مکانی مسدود شده است. آن را از تنظیمات دستگاه فعال کنید.',
      _ => null,
    };
  }

  Future<void> start() async {
    if (_running) return;

    _running = true;
    _consecutiveFailures = 0;

    // The driver's screen is often idle in a cradle; without this the OS
    // suspends the app and the bus disappears from the live map.
    unawaited(WakelockPlus.enable());

    _positionSubscription = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.bestForNavigation,
        // Ignore sub-5-metre jitter while stationary; it is noise, and every
        // accepted ping costs data and battery.
        distanceFilter: 5,
      ),
    ).listen(
      (position) => _latest = position,
      onError: (_) => _statusController.add(LocationReportStatus.gpsUnavailable),
    );

    _scheduleReport();
  }

  Future<void> stop() async {
    _running = false;

    await _positionSubscription?.cancel();
    _positionSubscription = null;

    _reportTimer?.cancel();
    _reportTimer = null;

    unawaited(WakelockPlus.disable());
  }

  void dispose() {
    unawaited(stop());
    unawaited(_statusController.close());
  }

  void _scheduleReport() {
    _reportTimer?.cancel();

    if (!_running) return;

    _reportTimer = Timer(Duration(seconds: _intervalSeconds.clamp(3, 60)), () async {
      await _report();
      _scheduleReport();
    });
  }

  Future<void> _report() async {
    final position = _latest;

    if (position == null) {
      _statusController.add(LocationReportStatus.waitingForFix);

      return;
    }

    try {
      final result = await _api.reportLocation(
        lat: position.latitude,
        lng: position.longitude,
        speedKmh: position.speed * 3.6,
        heading: position.heading >= 0 ? position.heading : null,
        accuracy: position.accuracy,
        altitude: position.altitude,
        recordedAt: position.timestamp,
      );

      _consecutiveFailures = 0;

      // Obey the server's requested cadence.
      final next = result['next_ping_in'];
      if (next is int && next > 0) _intervalSeconds = next;

      _statusController.add(LocationReportStatus.reporting);
    } on ApiException catch (error) {
      _consecutiveFailures++;

      // The trip ended underneath us (a supervisor closed it, or the shift was
      // force-closed): stop rather than retrying forever.
      if (error.code == 'no_active_trip' || error.code == 'trip_not_accepting_telemetry') {
        _statusController.add(LocationReportStatus.tripEnded);
        await stop();

        return;
      }

      // A handful of misses is a tunnel, not a fault.
      _statusController.add(
        _consecutiveFailures >= 4
            ? LocationReportStatus.offline
            : LocationReportStatus.reporting,
      );

      // Back off while offline so a long dead zone does not drain the battery.
      if (_consecutiveFailures >= 4) _intervalSeconds = 20;
    }
  }
}

enum LocationReportStatus { reporting, waitingForFix, gpsUnavailable, offline, tripEnded }
