import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../location_service.dart';
import '../../providers.dart';
import 'student_card.dart';

/// The manifest: every child on this run, in the order they are collected.
///
/// The two buttons on each row are the whole product. A child is picked up or
/// dropped off *here*, at this door, with the van's position attached, which is
/// what turns "the driver said so" into a record a parent can check. Undo
/// exists because a wrong tap at a kerb in the rain is a thing that happens,
/// and a driver who cannot correct it will stop tapping at all.
class ManifestScreen extends ConsumerStatefulWidget {
  const ManifestScreen({required this.tripUuid, super.key});

  final String tripUuid;

  @override
  ConsumerState<ManifestScreen> createState() => _ManifestScreenState();
}

class _ManifestScreenState extends ConsumerState<ManifestScreen> {
  bool _busy = false;

  Future<void> _start() async {
    final blocker = await SchoolLocationService.ensurePermissions();

    if (blocker != null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(blocker)));
      }

      return;
    }

    await _run(() async {
      final position = await Geolocator.getLastKnownPosition();

      await ref.read(transitApiProvider).startSchoolTrip(
            widget.tripUuid,
            lat: position?.latitude,
            lng: position?.longitude,
          );

      // Families can only see the van while the run is under way, so the
      // reporter starts here and not a moment earlier.
      unawaited(ref.read(schoolLocationServiceProvider).start());
    });
  }

  Future<void> _complete(SchoolTrip trip) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(Format.tr('school.complete_run')),
        content: Text(
          trip.isSettled
              ? Format.tr('school.complete_confirm')
              : Format.tr('school.complete_confirm_pending'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(Format.tr('common.cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(Format.tr('school.complete_run')),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    await _run(() async {
      final position = await Geolocator.getLastKnownPosition();

      await ref.read(transitApiProvider).completeSchoolTrip(
            widget.tripUuid,
            lat: position?.latitude,
            lng: position?.longitude,
          );

      await ref.read(schoolLocationServiceProvider).stop();
    });
  }

  /// Check one child on or off, with the van's position attached.
  Future<void> _check(SchoolTripStudent student, String action) async {
    await _run(() async {
      final position = action == 'absent' || action == 'reset'
          ? null
          : await Geolocator.getLastKnownPosition();

      await ref.read(transitApiProvider).checkSchoolStudent(
            student.uuid,
            action,
            lat: position?.latitude,
            lng: position?.longitude,
          );
    });
  }

  Future<void> _markAbsent(SchoolTripStudent student) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(Format.tr('school.mark_absent')),
        content: Text(
          Format.tr('school.mark_absent_confirm', {'name': student.name}),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(Format.tr('common.cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(Format.tr('school.mark_absent')),
          ),
        ],
      ),
    );

    if (confirmed == true) await _check(student, 'absent');
  }

  Future<void> _call(String? number) async {
    if (number == null) return;

    final uri = Uri(scheme: 'tel', path: Format.toLatinDigits(number));

    if (await canLaunchUrl(uri)) {
      await launchUrl(uri);
    }
  }

  Future<void> _run(Future<void> Function() action) async {
    setState(() => _busy = true);

    try {
      await action();
      ref.invalidate(schoolTripProvider(widget.tripUuid));
      ref.invalidate(schoolStateProvider);
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
    final trip = ref.watch(schoolTripProvider(widget.tripUuid));
    final reportStatus = ref.watch(schoolLocationStatusProvider).valueOrNull;

    return AppScaffold(
      title: Format.tr('school.manifest_title'),
      leading: const BackButton(),
      onRefresh: () async {
        ref.invalidate(schoolTripProvider(widget.tripUuid));
        await ref.read(schoolTripProvider(widget.tripUuid).future);
      },
      body: trip.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.brand400)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('school.manifest_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(schoolTripProvider(widget.tripUuid)),
        ),
        data: (run) => ListView(
          padding: const EdgeInsets.only(bottom: 32),
          children: [
            _RunHeader(trip: run, reportStatus: reportStatus),
            const SizedBox(height: AppSpacing.md),

            if (run.isScheduled)
              FilledButton.icon(
                onPressed: _busy ? null : _start,
                icon: const Icon(Icons.play_arrow_rounded),
                label: Text(Format.tr('school.start_run')),
              )
            else if (run.isLive)
              OutlinedButton.icon(
                onPressed: _busy ? null : () => _complete(run),
                icon: const Icon(Icons.flag_rounded, size: 18),
                label: Text(Format.tr('school.complete_run')),
              ),

            const SizedBox(height: AppSpacing.lg),

            if (run.students.isEmpty)
              EmptyState(
                icon: Icons.groups_outlined,
                message: Format.tr('school.no_students'),
              )
            else
              for (final student in run.students)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: StudentCard(
                    student: student,
                    // Nothing can be checked before the run starts: a pick-up
                    // recorded at eight for a van still in the yard is a lie
                    // in a record parents rely on.
                    enabled: run.isLive && !_busy,
                    isMorning: run.isMorning,
                    onPickUp: () => _check(student, 'pickup'),
                    onDropOff: () => _check(student, 'dropoff'),
                    onAbsent: () => _markAbsent(student),
                    onReset: () => _check(student, 'reset'),
                    onCallGuardian: () => _call(student.guardianPhone),
                    onCallEmergency: () => _call(student.emergencyContactPhone),
                  ),
                ),

            if (run.schoolName != null) ...[
              const SizedBox(height: AppSpacing.lg),
              _SchoolCard(trip: run),
            ],
          ],
        ),
      ),
    );
  }
}

class _RunHeader extends StatelessWidget {
  const _RunHeader({required this.trip, required this.reportStatus});

  final SchoolTrip trip;
  final SchoolReportStatus? reportStatus;

  ({String label, String token, IconData icon})? get _badge => switch (reportStatus) {
        SchoolReportStatus.reporting => (
            label: Format.tr('school.sharing_position'),
            token: 'success',
            icon: Icons.gps_fixed_rounded,
          ),
        SchoolReportStatus.waitingForFix => (
            label: Format.tr('shift.gps_waiting'),
            token: 'warning',
            icon: Icons.gps_not_fixed_rounded,
          ),
        SchoolReportStatus.gpsUnavailable => (
            label: Format.tr('shift.gps_unavailable'),
            token: 'danger',
            icon: Icons.gps_off_rounded,
          ),
        SchoolReportStatus.offline => (
            label: Format.tr('shift.server_unreachable'),
            token: 'danger',
            icon: Icons.cloud_off_rounded,
          ),
        _ => null,
      };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final badge = _badge;
    final remaining = trip.students.where((student) => !student.isSettled).length;

    return GlassCard(
      strong: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(
                trip.isMorning ? Icons.school_rounded : Icons.home_rounded,
                size: 18,
                color: trip.isMorning ? AppColors.warning : AppColors.brand400,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(trip.directionLabel, style: theme.textTheme.titleSmall),
                    Text(
                      [trip.routeName, trip.vehiclePlate].whereType<String>().join(' · '),
                      style: theme.textTheme.labelSmall,
                    ),
                  ],
                ),
              ),
              if (badge != null)
                StatusBadge(label: badge.label, colorToken: badge.token, icon: badge.icon)
              else
                StatusBadge(label: trip.statusLabel, colorToken: trip.statusColor),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: Format.tr('school.remaining'),
                  value: Format.number(remaining),
                  accent: remaining == 0 ? AppColors.brand300 : null,
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: Format.tr('school.picked_up'),
                  value: Format.number(trip.pickedUpCount),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: Format.tr('school.dropped_off'),
                  value: Format.number(trip.droppedOffCount),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: Format.tr('school.absent'),
                  value: Format.number(trip.absentCount),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Where everyone is going, or coming from.
class _SchoolCard extends StatelessWidget {
  const _SchoolCard({required this.trip});

  final SchoolTrip trip;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      child: Row(
        children: [
          const Icon(Icons.location_on_rounded, size: 18, color: AppColors.info),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  trip.isMorning
                      ? Format.tr('school.final_stop')
                      : Format.tr('school.starting_point'),
                  style: theme.textTheme.labelSmall,
                ),
                Text(trip.schoolName!, style: theme.textTheme.titleSmall),
                if (trip.schoolAddress != null)
                  Text(trip.schoolAddress!, style: theme.textTheme.labelSmall),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
