import 'dart:async';

import 'package:geolocator/geolocator.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

/// Position reporting for a school van.
///
/// The privacy rule is the design. Reports are sent only while a run is under
/// way, because outside one there is no family entitled to see where the van
/// is — the server refuses them, and this stops sending them rather than
/// retrying. What it publishes reaches exactly the families whose children are
/// on board, and stops reaching each of them the moment their own child's
/// journey ends.
class SchoolLocationService {
  SchoolLocationService(this._api);

  final TransitApi _api;

  StreamSubscription<Position>? _positionSubscription;
  Timer? _reportTimer;
  Position? _latest;

  int _intervalSeconds = 10;
  int _consecutiveFailures = 0;
  bool _running = false;

  final _statusController = StreamController<SchoolReportStatus>.broadcast();

  Stream<SchoolReportStatus> get status => _statusController.stream;

  bool get isRunning => _running;

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

    // A driver checking children on and off at a kerb must not be fighting a
    // screen that keeps sleeping, and a sleeping app stops reporting.
    unawaited(WakelockPlus.enable());

    _positionSubscription = Geolocator.getPositionStream(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 10,
      ),
    ).listen(
      (position) => _latest = position,
      onError: (_) => _statusController.add(SchoolReportStatus.gpsUnavailable),
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

    _reportTimer = Timer(Duration(seconds: _intervalSeconds.clamp(5, 60)), () async {
      await _report();
      _scheduleReport();
    });
  }

  Future<void> _report() async {
    final position = _latest;

    if (position == null) {
      _statusController.add(SchoolReportStatus.waitingForFix);

      return;
    }

    try {
      final result = await _api.reportSchoolLocation(
        lat: position.latitude,
        lng: position.longitude,
        speedKmh: position.speed * 3.6,
      );

      _consecutiveFailures = 0;

      // The run finished, or was completed from the company panel. There is
      // nobody entitled to this position any more, so stop sending it.
      if (result['accepted'] == false) {
        _statusController.add(SchoolReportStatus.runEnded);
        await stop();

        return;
      }

      final next = result['next_report_in'];
      if (next is int && next > 0) _intervalSeconds = next;

      _statusController.add(SchoolReportStatus.reporting);
    } on ApiException {
      _consecutiveFailures++;

      _statusController.add(
        _consecutiveFailures >= 4 ? SchoolReportStatus.offline : SchoolReportStatus.reporting,
      );

      if (_consecutiveFailures >= 4) _intervalSeconds = 25;
    }
  }
}

enum SchoolReportStatus { reporting, waitingForFix, gpsUnavailable, offline, runEnded }
