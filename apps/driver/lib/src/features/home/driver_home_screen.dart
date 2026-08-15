import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../location_service.dart';
import '../../providers.dart';
import 'shift_scan_screen.dart';

/// The driver's main screen: start and end a shift, run a trip, and see at a
/// glance whether the bus is actually reporting its position.
class DriverHomeScreen extends ConsumerStatefulWidget {
  const DriverHomeScreen({super.key});

  @override
  ConsumerState<DriverHomeScreen> createState() => _DriverHomeScreenState();
}

class _DriverHomeScreenState extends ConsumerState<DriverHomeScreen> {
  Timer? _refreshTimer;
  bool _busy = false;

  @override
  void initState() {
    super.initState();

    _refreshTimer = Timer.periodic(
      const Duration(seconds: 20),
      (_) => ref.invalidate(driverStateProvider),
    );

    // A trip closed by the control room must stop this device reporting too.
    WidgetsBinding.instance.addPostFrameCallback((_) => _watchLocationStatus());
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  void _watchLocationStatus() {
    ref.listenManual(locationStatusProvider, (previous, next) {
      if (next.valueOrNull == LocationReportStatus.tripEnded && mounted) {
        ref.invalidate(driverStateProvider);

        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('سفر پایان یافت. ارسال موقعیت متوقف شد.')),
        );
      }
    });
  }

  Future<void> _startShift() async {
    final blocker = await DriverLocationService.ensurePermissions();

    if (blocker != null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(blocker)));
      }

      return;
    }

    if (!mounted) return;

    final started = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => const ShiftScanScreen()),
    );

    if (started == true) ref.invalidate(driverStateProvider);
  }

  Future<void> _endShift() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('پایان شیفت'),
        content: const Text(
          'با پایان شیفت، سفر جاری بسته می‌شود و مسافران باقی‌مانده پیاده‌شده ثبت می‌شوند. ادامه می‌دهید؟',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('انصراف')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('پایان شیفت')),
        ],
      ),
    );

    if (confirmed != true) return;

    setState(() => _busy = true);

    try {
      final position = await Geolocator.getLastKnownPosition();

      await ref.read(transitApiProvider).endShift(
            lat: position?.latitude,
            lng: position?.longitude,
          );

      await ref.read(locationServiceProvider).stop();

      ref.invalidate(driverStateProvider);
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _tripAction(Future<void> Function() action, String failureContext) async {
    setState(() => _busy = true);

    try {
      await action();
      ref.invalidate(driverStateProvider);
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$failureContext: ${error.message}')),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(driverStateProvider);
    final locationStatus = ref.watch(locationStatusProvider).valueOrNull;

    return AppScaffold(
      title: 'شیفت من',
      actions: [
        IconButton(
          onPressed: () => ref.read(authControllerProvider.notifier).signOut(),
          icon: const Icon(Icons.logout_rounded, size: 20),
        ),
      ],
      onRefresh: () async {
        ref.invalidate(driverStateProvider);
        await ref.read(driverStateProvider.future);
      },
      body: state.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.info)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : 'دریافت اطلاعات ممکن نشد.',
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(driverStateProvider),
        ),
        data: (driver) => ListView(
          padding: const EdgeInsets.only(bottom: 110),
          children: [
            _DriverHeader(driver: driver),

            if (driver.licenseExpiringSoon) ...[
              const SizedBox(height: AppSpacing.md),
              _Warning(
                message: 'گواهینامه شما در تاریخ '
                    '${Format.dateTime(driver.licenseExpiresAt)} منقضی می‌شود.',
              ),
            ],

            const SizedBox(height: AppSpacing.lg),

            if (!driver.hasOpenShift)
              _StartShiftCard(onStart: _busy ? null : _startShift, driver: driver)
            else ...[
              _ShiftCard(
                shift: driver.shift!,
                locationStatus: locationStatus,
                onEnd: _busy ? null : _endShift,
              ),
              const SizedBox(height: AppSpacing.md),
              if (driver.hasLiveTrip)
                _TripCard(
                  trip: driver.trip!,
                  busy: _busy,
                  onPause: () => _tripAction(
                    ref.read(transitApiProvider).pauseTrip,
                    'توقف سفر ناموفق بود',
                  ),
                  onResume: () => _tripAction(
                    ref.read(transitApiProvider).resumeTrip,
                    'ادامه سفر ناموفق بود',
                  ),
                  onComplete: () => _tripAction(
                    () async {
                      await ref.read(transitApiProvider).completeTrip();
                      await ref.read(locationServiceProvider).stop();
                    },
                    'پایان سفر ناموفق بود',
                  ),
                )
              else
                _StartTripCard(
                  busy: _busy,
                  onStarted: () {
                    ref.invalidate(driverStateProvider);
                    unawaited(ref.read(locationServiceProvider).start());
                  },
                ),
            ],
          ],
        ),
      ),
    );
  }
}

class _DriverHeader extends StatelessWidget {
  const _DriverHeader({required this.driver});

  final DriverState driver;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      strong: true,
      child: Row(
        children: [
          Container(
            width: 50,
            height: 50,
            decoration: BoxDecoration(
              gradient: const LinearGradient(colors: [AppColors.info, Color(0xFF0B5FA5)]),
              borderRadius: BorderRadius.circular(16),
            ),
            child: const Icon(Icons.badge_outlined, color: Colors.white),
          ),
          const SizedBox(width: AppSpacing.lg),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(driver.name, style: theme.textTheme.titleMedium),
                const SizedBox(height: 3),
                Text(
                  [
                    if (driver.employeeCode != null) Format.digits(driver.employeeCode!),
                    '${Format.number(driver.totalTrips)} سفر',
                  ].join(' · '),
                  style: theme.textTheme.labelSmall,
                ),
              ],
            ),
          ),
          StatusBadge(
            label: driver.status == 'active' ? 'فعال' : 'غیرفعال',
            colorToken: driver.status == 'active' ? 'success' : 'danger',
          ),
        ],
      ),
    );
  }
}

class _StartShiftCard extends StatelessWidget {
  const _StartShiftCard({required this.onStart, required this.driver});

  final VoidCallback? onStart;
  final DriverState driver;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      padding: const EdgeInsets.all(AppSpacing.xl),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 74,
              height: 74,
              decoration: BoxDecoration(
                color: AppColors.info.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(24),
              ),
              child: const Icon(Icons.qr_code_scanner_rounded, size: 36, color: AppColors.info),
            ),
          ),
          const SizedBox(height: AppSpacing.xl),
          Text('شروع شیفت', textAlign: TextAlign.center, style: theme.textTheme.titleLarge),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'کد QR داخل اتوبوس را اسکن کنید. سامانه بررسی می‌کند که این اتوبوس به شما تخصیص داده شده باشد.',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall,
          ),

          if (driver.assignedBuses.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.xl),
            Text('اتوبوس‌های تخصیص‌یافته', style: theme.textTheme.labelMedium),
            const SizedBox(height: AppSpacing.sm),
            for (final bus in driver.assignedBuses)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                  decoration: BoxDecoration(
                    color: AppColors.glassFill,
                    borderRadius: AppRadii.fieldBorder,
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.directions_bus_rounded, size: 16, color: AppColors.ink400),
                      const SizedBox(width: 8),
                      Text('اتوبوس ${Format.digits(bus.number)}',
                          style: theme.textTheme.titleSmall),
                      const Spacer(),
                      if (bus.lineCode != null)
                        Text('خط ${Format.digits(bus.lineCode!)}',
                            style: theme.textTheme.labelSmall),
                    ],
                  ),
                ),
              ),
          ],

          const SizedBox(height: AppSpacing.xl),
          FilledButton.icon(
            onPressed: onStart,
            style: FilledButton.styleFrom(backgroundColor: AppColors.info),
            icon: const Icon(Icons.play_arrow_rounded),
            label: const Text('اسکن و شروع شیفت'),
          ),
        ],
      ),
    );
  }
}

class _ShiftCard extends StatelessWidget {
  const _ShiftCard({required this.shift, required this.locationStatus, required this.onEnd});

  final ShiftSummary shift;
  final LocationReportStatus? locationStatus;
  final VoidCallback? onEnd;

  ({String label, String token, IconData icon}) get _locationBadge => switch (locationStatus) {
        LocationReportStatus.reporting =>
          (label: 'ارسال موقعیت فعال', token: 'success', icon: Icons.gps_fixed_rounded),
        LocationReportStatus.waitingForFix =>
          (label: 'در انتظار GPS', token: 'warning', icon: Icons.gps_not_fixed_rounded),
        LocationReportStatus.gpsUnavailable =>
          (label: 'GPS در دسترس نیست', token: 'danger', icon: Icons.gps_off_rounded),
        LocationReportStatus.offline =>
          (label: 'ارتباط با سرور قطع است', token: 'danger', icon: Icons.cloud_off_rounded),
        _ => (label: 'آماده', token: 'neutral', icon: Icons.pause_circle_outline_rounded),
      };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final badge = _locationBadge;

    return GlassCard(
      strong: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const LiveDot(color: AppColors.info),
              const SizedBox(width: 8),
              Text('شیفت باز', style: theme.textTheme.titleSmall),
              const Spacer(),
              StatusBadge(label: badge.label, colorToken: badge.token, icon: badge.icon),
            ],
          ),
          const SizedBox(height: AppSpacing.lg),

          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: 'اتوبوس',
                  value: Format.digits(shift.busNumber ?? '—'),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: 'مدت شیفت',
                  value: Format.duration(shift.durationMinutes * 60),
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          Row(
            children: [
              Expanded(
                child: StatTile(label: 'سفرها', value: Format.number(shift.tripCount)),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: 'مسافران',
                  value: Format.number(shift.passengerCount),
                  accent: AppColors.brand300,
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(label: 'درآمد', value: shift.formattedRevenue),
              ),
            ],
          ),

          const SizedBox(height: AppSpacing.lg),
          OutlinedButton.icon(
            onPressed: onEnd,
            icon: const Icon(Icons.stop_circle_outlined, size: 18),
            label: const Text('پایان شیفت'),
          ),
        ],
      ),
    );
  }
}

class _StartTripCard extends ConsumerStatefulWidget {
  const _StartTripCard({required this.busy, required this.onStarted});

  final bool busy;
  final VoidCallback onStarted;

  @override
  ConsumerState<_StartTripCard> createState() => _StartTripCardState();
}

class _StartTripCardState extends ConsumerState<_StartTripCard> {
  List<Map<String, dynamic>> _routes = const [];
  int? _selectedRouteId;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    unawaited(_loadRoutes());
  }

  Future<void> _loadRoutes() async {
    try {
      // The shift-start response carries the routes; re-derive them from the
      // network list so this card also works after an app restart.
      final lines = await ref.read(transitApiProvider).lines();

      final routes = <Map<String, dynamic>>[];

      for (final line in lines) {
        routes.add({'id': line.id, 'label': 'خط ${line.code} — ${line.destination ?? line.name}'});
      }

      if (mounted) {
        setState(() {
          _routes = routes;
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _start() async {
    if (_selectedRouteId == null) return;

    try {
      await ref.read(transitApiProvider).startTrip(_selectedRouteId!);
      widget.onStarted();
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('شروع سرویس', style: theme.textTheme.titleSmall),
          const SizedBox(height: AppSpacing.sm),
          Text(
            'مسیری که در این شیفت اجرا می‌کنید را انتخاب کنید.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: AppSpacing.lg),

          if (_loading)
            const ShimmerBox(height: 52)
          else
            DropdownButtonFormField<int>(
              initialValue: _selectedRouteId,
              dropdownColor: AppColors.ink800,
              hint: const Text('انتخاب مسیر'),
              isExpanded: true,
              items: [
                for (final route in _routes)
                  DropdownMenuItem(
                    value: route['id'] as int,
                    child: Text(
                      Format.digits(route['label'] as String),
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
              onChanged: (value) => setState(() => _selectedRouteId = value),
            ),

          const SizedBox(height: AppSpacing.lg),
          FilledButton.icon(
            onPressed: widget.busy || _selectedRouteId == null ? null : _start,
            icon: const Icon(Icons.play_arrow_rounded),
            label: const Text('شروع سرویس و ارسال موقعیت'),
          ),
        ],
      ),
    );
  }
}

class _TripCard extends StatelessWidget {
  const _TripCard({
    required this.trip,
    required this.busy,
    required this.onPause,
    required this.onResume,
    required this.onComplete,
  });

  final TripSummary trip;
  final bool busy;
  final VoidCallback onPause;
  final VoidCallback onResume;
  final VoidCallback onComplete;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      strong: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Text('سرویس جاری', style: theme.textTheme.titleSmall),
              const Spacer(),
              if (trip.isOffRoute)
                const StatusBadge(label: 'خارج از مسیر', colorToken: 'warning')
              else if (trip.isPaused)
                const StatusBadge(label: 'متوقف موقت', colorToken: 'warning')
              else
                const StatusBadge(label: 'در حال سرویس', colorToken: 'success'),
            ],
          ),
          const SizedBox(height: AppSpacing.md),

          Text(
            'خط ${Format.digits(trip.lineCode ?? '—')} — ${trip.destination ?? trip.lineName ?? ''}',
            style: theme.textTheme.titleMedium,
          ),

          const SizedBox(height: AppSpacing.lg),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: 'مسافران داخل اتوبوس',
                  value: Format.number(trip.passengerCount),
                  accent: AppColors.brand300,
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: 'ایستگاه بعدی',
                  value: trip.nextStop ?? '—',
                  caption: trip.nextStopDistance == null
                      ? null
                      : Format.distance(trip.nextStopDistance),
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: 'زمان تا ایستگاه بعدی',
                  value: Format.minutes(trip.nextStopEtaSeconds),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: 'مسافت طی‌شده',
                  value: Format.distance(trip.distanceMeters),
                ),
              ),
            ],
          ),

          const SizedBox(height: AppSpacing.lg),
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: busy ? null : (trip.isPaused ? onResume : onPause),
                  icon: Icon(
                    trip.isPaused ? Icons.play_arrow_rounded : Icons.pause_rounded,
                    size: 18,
                  ),
                  label: Text(trip.isPaused ? 'ادامه' : 'توقف موقت'),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: FilledButton.icon(
                  onPressed: busy ? null : onComplete,
                  style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
                  icon: const Icon(Icons.flag_rounded, size: 18),
                  label: const Text('پایان سفر'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Warning extends StatelessWidget {
  const _Warning({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.warning.withValues(alpha: 0.10),
        borderRadius: AppRadii.fieldBorder,
        border: Border.all(color: AppColors.warning.withValues(alpha: 0.28)),
      ),
      child: Row(
        children: [
          const Icon(Icons.warning_amber_rounded, size: 18, color: AppColors.warning),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: AppColors.warning),
            ),
          ),
        ],
      ),
    );
  }
}
