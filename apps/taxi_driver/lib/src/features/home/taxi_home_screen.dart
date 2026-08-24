import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../location_service.dart';
import '../../providers.dart';
import 'charter_sheet.dart';
import 'fare_code_card.dart';
import 'start_shift_sheet.dart';

/// The driver's main screen.
///
/// One question dominates it: what is this car offering right now. That is the
/// mode on the open shift, not a property of the vehicle, so it can be changed
/// mid-shift — a driver who has been running a fixed line all morning takes a
/// charter in the afternoon without ending anything.
class TaxiHomeScreen extends ConsumerStatefulWidget {
  const TaxiHomeScreen({super.key});

  @override
  ConsumerState<TaxiHomeScreen> createState() => _TaxiHomeScreenState();
}

class _TaxiHomeScreenState extends ConsumerState<TaxiHomeScreen> {
  Timer? _refreshTimer;
  bool _busy = false;

  @override
  void initState() {
    super.initState();

    _refreshTimer = Timer.periodic(
      const Duration(seconds: 20),
      (_) => ref.invalidate(taxiStateProvider),
    );

    WidgetsBinding.instance.addPostFrameCallback((_) => _watchLocationStatus());
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  void _watchLocationStatus() {
    ref.listenManual(taxiLocationStatusProvider, (previous, next) {
      if (next.valueOrNull == TaxiReportStatus.shiftEnded && mounted) {
        ref.invalidate(taxiStateProvider);

        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(Format.tr('taxi.shift_closed_elsewhere'))),
        );
      }
    });
  }

  Future<void> _startShift(TaxiDriverState state) async {
    final blocker = await TaxiLocationService.ensurePermissions();

    if (blocker != null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(blocker)));
      }

      return;
    }

    if (!mounted) return;

    final started = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => StartShiftSheet(taxis: state.assignedTaxis),
    );

    if (started != true) return;

    ref.invalidate(taxiStateProvider);
    unawaited(ref.read(taxiLocationServiceProvider).start());
  }

  Future<void> _endShift() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(Format.tr('taxi.end_shift')),
        content: Text(Format.tr('taxi.end_shift_confirm')),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(Format.tr('common.cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(Format.tr('taxi.end_shift')),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    await _run(() async {
      final position = await Geolocator.getLastKnownPosition();

      await ref.read(transitApiProvider).endTaxiShift(
            lat: position?.latitude,
            lng: position?.longitude,
          );

      await ref.read(taxiLocationServiceProvider).stop();
    });
  }

  Future<void> _switchMode(TaxiServiceType mode) async {
    if (mode == TaxiServiceType.line) {
      final lines = await ref.read(taxiLinesProvider.future);

      if (!mounted) return;

      final lineId = await showModalBottomSheet<int>(
        context: context,
        backgroundColor: Colors.transparent,
        builder: (_) => _LinePicker(lines: lines),
      );

      if (lineId == null) return;

      await _run(() => ref.read(transitApiProvider).switchTaxiMode(
            serviceType: mode.value,
            taxiLineId: lineId,
          ));

      return;
    }

    await _run(() => ref.read(transitApiProvider).switchTaxiMode(serviceType: mode.value));
  }

  Future<void> _setCharter() async {
    final amount = await showModalBottomSheet<int>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => const CharterSheet(),
    );

    if (amount == null) return;

    await _run(() => ref.read(transitApiProvider).setCharterAmount(amount));
  }

  Future<void> _clearCharter() =>
      _run(() => ref.read(transitApiProvider).clearCharterAmount());

  Future<void> _run(Future<void> Function() action) async {
    setState(() => _busy = true);

    try {
      await action();
      ref.invalidate(taxiStateProvider);
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(taxiStateProvider);
    final reportStatus = ref.watch(taxiLocationStatusProvider).valueOrNull;

    return AppScaffold(
      title: Format.tr('taxi.title'),
      actions: [
        IconButton(
          onPressed: () => ref.read(authControllerProvider.notifier).signOut(),
          icon: const Icon(Icons.logout_rounded, size: 20),
        ),
      ],
      onRefresh: () async {
        ref.invalidate(taxiStateProvider);
        await ref.read(taxiStateProvider.future);
      },
      body: state.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.warning)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('taxi.load_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(taxiStateProvider),
        ),
        data: (driver) => ListView(
          padding: const EdgeInsets.only(bottom: 110),
          children: [
            _DriverHeader(driver: driver),
            if (driver.blocker != null) ...[
              const SizedBox(height: AppSpacing.md),
              _Notice(
                message: Format.tr('taxi.blockers.${driver.blocker}'),
                color: AppColors.danger,
                icon: Icons.block_rounded,
              ),
            ],
            const SizedBox(height: AppSpacing.lg),
            if (!driver.hasOpenShift)
              _StartShiftCard(
                driver: driver,
                onStart: _busy || !driver.mayDrive ? null : () => _startShift(driver),
              )
            else ...[
              _ShiftCard(
                shift: driver.shift!,
                reportStatus: reportStatus,
                busy: _busy,
                onEnd: _endShift,
              ),
              const SizedBox(height: AppSpacing.md),
              _ModePicker(
                current: driver.shift!.serviceType,
                allowed: driver.allowedModes,
                busy: _busy,
                onChanged: _switchMode,
              ),
              const SizedBox(height: AppSpacing.md),
              if (driver.shift!.serviceType == TaxiServiceType.charter)
                _CharterCard(
                  shift: driver.shift!,
                  busy: _busy,
                  onSet: _setCharter,
                  onClear: _clearCharter,
                ),
              if (driver.shift!.serviceType == TaxiServiceType.meter) const _MeterCard(),
              if (driver.shift!.serviceType == TaxiServiceType.line)
                _LineCard(shift: driver.shift!),
              const SizedBox(height: AppSpacing.md),
              FareCodeCard(qr: driver.qr, serviceType: driver.shift!.serviceType),
            ],
          ],
        ),
      ),
    );
  }
}

class _DriverHeader extends StatelessWidget {
  const _DriverHeader({required this.driver});

  final TaxiDriverState driver;

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
              gradient: const LinearGradient(colors: [AppColors.warning, Color(0xFFB54708)]),
              borderRadius: BorderRadius.circular(16),
            ),
            child: const Icon(Icons.local_taxi_rounded, color: Colors.white),
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
                  Format.tr('taxi.pending_settlement', {
                    'amount': Format.money(driver.pendingSettlement),
                  }),
                  style: theme.textTheme.labelSmall,
                ),
              ],
            ),
          ),
          StatusBadge(
            label: driver.hasOpenShift
                ? Format.tr('taxi.on_shift')
                : Format.tr('taxi.off_shift'),
            colorToken: driver.hasOpenShift ? 'success' : 'neutral',
          ),
        ],
      ),
    );
  }
}

class _StartShiftCard extends StatelessWidget {
  const _StartShiftCard({required this.driver, required this.onStart});

  final TaxiDriverState driver;
  final VoidCallback? onStart;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (driver.assignedTaxis.isEmpty) {
      return EmptyState(
        icon: Icons.no_transfer_rounded,
        message: Format.tr('taxi.no_assigned_taxi'),
      );
    }

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
                color: AppColors.warning.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(24),
              ),
              child: const Icon(Icons.play_circle_outline_rounded,
                  size: 36, color: AppColors.warning),
            ),
          ),
          const SizedBox(height: AppSpacing.xl),
          Text(
            Format.tr('taxi.start_shift'),
            textAlign: TextAlign.center,
            style: theme.textTheme.titleLarge,
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            Format.tr('taxi.start_shift_body'),
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: AppSpacing.xl),
          FilledButton.icon(
            onPressed: onStart,
            style: FilledButton.styleFrom(backgroundColor: AppColors.warning),
            icon: const Icon(Icons.play_arrow_rounded),
            label: Text(Format.tr('taxi.choose_car_and_mode')),
          ),
        ],
      ),
    );
  }
}

class _ShiftCard extends StatelessWidget {
  const _ShiftCard({
    required this.shift,
    required this.reportStatus,
    required this.busy,
    required this.onEnd,
  });

  final TaxiShift shift;
  final TaxiReportStatus? reportStatus;
  final bool busy;
  final VoidCallback onEnd;

  ({String label, String token, IconData icon}) get _badge => switch (reportStatus) {
        TaxiReportStatus.reporting => (
            label: Format.tr('shift.gps_reporting'),
            token: 'success',
            icon: Icons.gps_fixed_rounded,
          ),
        TaxiReportStatus.discarded => (
            label: Format.tr('taxi.gps_weak'),
            token: 'warning',
            icon: Icons.gps_not_fixed_rounded,
          ),
        TaxiReportStatus.waitingForFix => (
            label: Format.tr('shift.gps_waiting'),
            token: 'warning',
            icon: Icons.gps_not_fixed_rounded,
          ),
        TaxiReportStatus.gpsUnavailable => (
            label: Format.tr('shift.gps_unavailable'),
            token: 'danger',
            icon: Icons.gps_off_rounded,
          ),
        TaxiReportStatus.offline => (
            label: Format.tr('shift.server_unreachable'),
            token: 'danger',
            icon: Icons.cloud_off_rounded,
          ),
        _ => (
            label: Format.tr('shift.idle'),
            token: 'neutral',
            icon: Icons.pause_circle_outline_rounded,
          ),
      };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final badge = _badge;

    return GlassCard(
      strong: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const LiveDot(color: AppColors.warning),
              const SizedBox(width: 8),
              Text(
                Format.tr('taxi.car_on_shift', {'number': Format.digits(shift.taxiNumber ?? '—')}),
                style: theme.textTheme.titleSmall,
              ),
              const Spacer(),
              StatusBadge(label: badge.label, colorToken: badge.token, icon: badge.icon),
            ],
          ),
          const SizedBox(height: AppSpacing.lg),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: Format.tr('taxi.boardings'),
                  value: Format.number(shift.boardingCount),
                  accent: AppColors.brand300,
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: Format.tr('taxi.alightings'),
                  value: Format.number(shift.alightingCount),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: Format.tr('taxi.onboard'),
                  value: Format.number(shift.onboardCount),
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: Format.tr('taxi.duration'),
                  value: Format.duration(shift.durationMinutes * 60),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: Format.tr('taxi.gross'),
                  value: shift.formattedGross,
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: Format.tr('taxi.net'),
                  value: shift.formattedNet,
                  accent: AppColors.brand300,
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.lg),
          OutlinedButton.icon(
            onPressed: busy ? null : onEnd,
            icon: const Icon(Icons.stop_circle_outlined, size: 18),
            label: Text(Format.tr('taxi.end_shift')),
          ),
        ],
      ),
    );
  }
}

/// The three products, as three buttons.
///
/// Only the modes this car is licensed for are offered: letting a driver pick
/// one the vehicle may not run would produce a refusal a moment later.
class _ModePicker extends StatelessWidget {
  const _ModePicker({
    required this.current,
    required this.allowed,
    required this.busy,
    required this.onChanged,
  });

  final TaxiServiceType current;
  final List<TaxiServiceType> allowed;
  final bool busy;
  final ValueChanged<TaxiServiceType> onChanged;

  static Color colorFor(TaxiServiceType mode) => switch (mode) {
        TaxiServiceType.line => AppColors.brand500,
        TaxiServiceType.charter => AppColors.warning,
        TaxiServiceType.meter => AppColors.info,
      };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(Format.tr('taxi.offering'), style: theme.textTheme.titleSmall),
          const SizedBox(height: AppSpacing.sm),
          Text(Format.tr('taxi.offering_body'), style: theme.textTheme.bodySmall),
          const SizedBox(height: AppSpacing.md),
          Row(
            children: [
              for (final mode in allowed) ...[
                Expanded(
                  child: _ModeButton(
                    mode: mode,
                    selected: mode == current,
                    onTap: busy || mode == current ? null : () => onChanged(mode),
                  ),
                ),
                if (mode != allowed.last) const SizedBox(width: AppSpacing.sm),
              ],
            ],
          ),
        ],
      ),
    );
  }
}

class _ModeButton extends StatelessWidget {
  const _ModeButton({required this.mode, required this.selected, required this.onTap});

  final TaxiServiceType mode;
  final bool selected;
  final VoidCallback? onTap;

  IconData get _icon => switch (mode) {
        TaxiServiceType.line => Icons.alt_route_rounded,
        TaxiServiceType.charter => Icons.handshake_outlined,
        TaxiServiceType.meter => Icons.speed_rounded,
      };

  @override
  Widget build(BuildContext context) {
    final color = _ModePicker.colorFor(mode);

    return InkWell(
      onTap: onTap,
      borderRadius: AppRadii.fieldBorder,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 8),
        decoration: BoxDecoration(
          color: selected ? color.withValues(alpha: 0.18) : AppColors.glassFill,
          borderRadius: AppRadii.fieldBorder,
          border: Border.all(
            color: selected ? color : AppColors.glassBorder,
            width: selected ? 1.5 : 1,
          ),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(_icon, size: 22, color: selected ? color : AppColors.ink400),
            const SizedBox(height: 6),
            Text(
              mode.label,
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 12,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                color: selected ? color : AppColors.ink300,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The charter price the driver has named, and how long it stands.
class _CharterCard extends StatelessWidget {
  const _CharterCard({
    required this.shift,
    required this.busy,
    required this.onSet,
    required this.onClear,
  });

  final TaxiShift shift;
  final bool busy;
  final VoidCallback onSet;
  final VoidCallback onClear;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final amount = shift.pendingCharterAmount;

    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Icon(Icons.handshake_outlined, size: 18, color: AppColors.warning),
              const SizedBox(width: 8),
              Text(Format.tr('taxi.charter_price'), style: theme.textTheme.titleSmall),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          if (amount == null) ...[
            Text(Format.tr('taxi.charter_none'), style: theme.textTheme.bodySmall),
            const SizedBox(height: AppSpacing.md),
            FilledButton.icon(
              onPressed: busy ? null : onSet,
              style: FilledButton.styleFrom(backgroundColor: AppColors.warning),
              icon: const Icon(Icons.edit_rounded, size: 18),
              label: Text(Format.tr('taxi.name_price')),
            ),
          ] else ...[
            Center(
              child: Text(
                Format.money(amount),
                style: theme.textTheme.displaySmall?.copyWith(color: AppColors.warning),
              ),
            ),
            const SizedBox(height: AppSpacing.sm),
            Text(
              Format.tr('taxi.charter_waiting'),
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: AppSpacing.md),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: busy ? null : onClear,
                    child: Text(Format.tr('taxi.cancel_price')),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: FilledButton(
                    onPressed: busy ? null : onSet,
                    style: FilledButton.styleFrom(backgroundColor: AppColors.warning),
                    child: Text(Format.tr('taxi.change_price')),
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// The running meter, as the car's own position reports have advanced it.
class _MeterCard extends ConsumerWidget {
  const _MeterCard();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ride = ref.watch(taxiMeterProvider).valueOrNull;
    final theme = Theme.of(context);

    return GlassCard(
      strong: ride != null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Icon(Icons.speed_rounded, size: 18, color: AppColors.info),
              const SizedBox(width: 8),
              Text(Format.tr('taxi.meter'), style: theme.textTheme.titleSmall),
              const Spacer(),
              if (ride != null) const LiveDot(color: AppColors.info),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          if (ride == null)
            Text(Format.tr('taxi.meter_idle'), style: theme.textTheme.bodySmall)
          else ...[
            Center(
              child: Text(
                ride.currentFare?.formattedAmount ?? ride.formattedFare,
                style: theme.textTheme.displaySmall?.copyWith(color: AppColors.info),
              ),
            ),
            const SizedBox(height: AppSpacing.md),
            Row(
              children: [
                Expanded(
                  child: StatTile(
                    label: Format.tr('taxi.distance'),
                    value: Format.distance(
                      ride.currentFare?.distanceMeters ?? ride.distanceMeters,
                    ),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: StatTile(
                    label: Format.tr('taxi.waiting'),
                    value: Format.duration(
                      ride.currentFare?.waitingSeconds ?? ride.waitingSeconds,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            Text(
              Format.tr('taxi.meter_note'),
              style: theme.textTheme.labelSmall,
            ),
          ],
        ],
      ),
    );
  }
}

class _LineCard extends StatelessWidget {
  const _LineCard({required this.shift});

  final TaxiShift shift;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      child: Row(
        children: [
          const Icon(Icons.alt_route_rounded, size: 18, color: AppColors.brand400),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  shift.lineName ?? Format.tr('taxi.no_line'),
                  style: theme.textTheme.titleSmall,
                ),
                if (shift.lineCode != null)
                  Text(
                    Format.tr('shift.line', {'code': Format.digits(shift.lineCode!)}),
                    style: theme.textTheme.labelSmall,
                  ),
              ],
            ),
          ),
          Text(
            shift.formattedLineFare ?? '—',
            style: theme.textTheme.titleMedium?.copyWith(color: AppColors.brand300),
          ),
        ],
      ),
    );
  }
}

class _LinePicker extends StatelessWidget {
  const _LinePicker({required this.lines});

  final List<TaxiLine> lines;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return SafeArea(
      child: Container(
        margin: const EdgeInsets.all(AppSpacing.md),
        padding: const EdgeInsets.all(AppSpacing.lg),
        decoration: BoxDecoration(
          color: AppColors.ink850,
          borderRadius: AppRadii.cardBorder,
          border: Border.all(color: AppColors.glassBorderStrong),
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(Format.tr('taxi.choose_line'), style: theme.textTheme.titleMedium),
            const SizedBox(height: AppSpacing.md),
            if (lines.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.xl),
                child: Text(
                  Format.tr('taxi.no_lines'),
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall,
                ),
              )
            else
              Flexible(
                child: ListView.separated(
                  shrinkWrap: true,
                  itemCount: lines.length,
                  separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
                  itemBuilder: (context, index) {
                    final line = lines[index];

                    return ListTile(
                      tileColor: AppColors.glassFill,
                      shape: const RoundedRectangleBorder(borderRadius: AppRadii.fieldBorder),
                      title: Text(line.name, style: theme.textTheme.titleSmall),
                      subtitle: Text(
                        Format.digits('${line.origin ?? ''} → ${line.destination ?? ''}'),
                        style: theme.textTheme.labelSmall,
                      ),
                      trailing: Text(
                        line.formattedFare,
                        style: theme.textTheme.titleSmall?.copyWith(color: AppColors.brand300),
                      ),
                      onTap: () => Navigator.pop(context, line.id),
                    );
                  },
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.message, required this.color, required this.icon});

  final String message;
  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: AppRadii.fieldBorder,
        border: Border.all(color: color.withValues(alpha: 0.28)),
      ),
      child: Row(
        children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(color: color),
            ),
          ),
        ],
      ),
    );
  }
}
