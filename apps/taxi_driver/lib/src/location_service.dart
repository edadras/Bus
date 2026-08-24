import 'dart:async';

import 'package:geolocator/geolocator.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

/// Position reporting for a taxi on shift.
///
/// This does more work than the bus equivalent, because for a taxi the report
/// *is* the meter. Distance is measured from the car's own reports and never
/// from the passenger's phone: a phone that is switched off, put in a bag or
/// deliberately spoofed must not be able to change what a ride costs. The
/// server does the arithmetic and hands back the fare as it stands, which this
/// republishes so the driver's screen and the passenger's agree.
///
/// The cadence comes back from the server with each accepted report, so
/// battery cost is tuned centrally rather than guessed in the app.
class TaxiLocationService {
  TaxiLocationService(this._api);

  final TransitApi _api;

  StreamSubscription<Position>? _positionSubscription;
  Timer? _reportTimer;
  Position? _latest;

  int _intervalSeconds = 8;
  int _consecutiveFailures = 0;
  bool _running = false;

  final _statusController = StreamController<TaxiReportStatus>.broadcast();
  final _meterController = StreamController<TaxiRide?>.broadcast();

  Stream<TaxiReportStatus> get status => _statusController.stream;

  /// The ride the meter is currently running on, or null when the car is free.
  Stream<TaxiRide?> get meter => _meterController.stream;

  bool get isRunning => _running;

  /// Ask for the permissions a shift needs. Returns the reason it cannot
  /// start, or null when everything required has been granted.
  static Future<String?> ensurePermissions() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      return Format.tr('location.service_off');
    }

    var permission = await Geolocator.checkPermission();

    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }

    return switch (permission) {
      LocationPermission.denied => Format.tr('location.permission_needed'),
      LocationPermission.deniedForever => Format.tr('location.permission_blocked'),
      _ => null,
    };
  }

  Future<void> start() async {
    if (_running) return;

    _running = true;
    _consecutiveFailures = 0;

    // The fare code is on screen for passengers to scan and the car is on a
    // live map; a sleeping device breaks both.
    unawaited(WakelockPlus.enable());

    _positionSubscription = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.bestForNavigation,
        // No distance filter. A taxi standing in traffic is billed for waiting
        // time, and that is only measurable if it keeps reporting while still.
        distanceFilter: 0,
      ),
    ).listen(
      (position) => _latest = position,
      onError: (_) => _statusController.add(TaxiReportStatus.gpsUnavailable),
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
    unawaited(_meterController.close());
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
      _statusController.add(TaxiReportStatus.waitingForFix);

      return;
    }

    try {
      final result = await _api.reportTaxiLocation(
        lat: position.latitude,
        lng: position.longitude,
        speedKmh: position.speed * 3.6,
        accuracy: position.accuracy,
        recordedAt: position.timestamp,
      );

      _consecutiveFailures = 0;

      final next = result['next_report_in'];
      if (next is int && next > 0) _intervalSeconds = next;

      final ride = result['ride'];
      _meterController.add(
        ride is Map<String, dynamic> ? TaxiRide.fromJson(ride) : null,
      );

      _statusController.add(
        result['accepted'] == false ? TaxiReportStatus.discarded : TaxiReportStatus.reporting,
      );
    } on ApiException catch (error) {
      _consecutiveFailures++;

      // The shift ended underneath us — closed by the control room, or force
      // closed overnight. Stop rather than retrying forever.
      if (error.code == 'no_open_shift') {
        _statusController.add(TaxiReportStatus.shiftEnded);
        await stop();

        return;
      }

      // A handful of misses is an underpass, not a fault.
      _statusController.add(
        _consecutiveFailures >= 4 ? TaxiReportStatus.offline : TaxiReportStatus.reporting,
      );

      if (_consecutiveFailures >= 4) _intervalSeconds = 20;
    }
  }
}

enum TaxiReportStatus {
  reporting,

  /// The server took the report but did not bill it — poor accuracy, an
  /// implausible jump, or one sample too soon after the last.
  discarded,
  waitingForFix,
  gpsUnavailable,
  offline,
  shiftEnded,
}
