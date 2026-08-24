import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';
import '../manifest/manifest_screen.dart';

/// Today's runs.
///
/// Usually two — out in the morning, back in the afternoon — so this is a
/// short list on purpose. Tapping one opens the manifest, which is where the
/// driver actually works.
class SchoolRunsScreen extends ConsumerWidget {
  const SchoolRunsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(schoolStateProvider);
    final theme = Theme.of(context);

    return AppScaffold(
      title: Format.tr('school.runs_title'),
      actions: [
        IconButton(
          onPressed: () => ref.read(authControllerProvider.notifier).signOut(),
          icon: const Icon(Icons.logout_rounded, size: 20),
        ),
      ],
      onRefresh: () async {
        ref.invalidate(schoolStateProvider);
        await ref.read(schoolStateProvider.future);
      },
      body: state.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.brand400)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('school.load_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(schoolStateProvider),
        ),
        data: (driver) => ListView(
          padding: const EdgeInsets.only(bottom: 32),
          children: [
            GlassCard(
              strong: true,
              child: Row(
                children: [
                  Container(
                    width: 46,
                    height: 46,
                    decoration: BoxDecoration(
                      color: AppColors.brand500.withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(15),
                    ),
                    child: const Icon(Icons.airport_shuttle_rounded, color: AppColors.brand400),
                  ),
                  const SizedBox(width: AppSpacing.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(driver.name, style: theme.textTheme.titleMedium),
                        Text(Format.digits(driver.date), style: theme.textTheme.labelSmall),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            if (driver.blocker != null) ...[
              const SizedBox(height: AppSpacing.md),
              _Notice(message: Format.tr('school.blockers.${driver.blocker}')),
            ],
            const SizedBox(height: AppSpacing.lg),
            if (driver.trips.isEmpty)
              EmptyState(
                icon: Icons.event_busy_rounded,
                message: Format.tr('school.no_runs_today'),
              )
            else
              for (final trip in driver.trips)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.md),
                  child: _RunCard(
                    trip: trip,
                    enabled: driver.mayDrive,
                    onOpen: () => Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => ManifestScreen(tripUuid: trip.uuid),
                      ),
                    ),
                  ),
                ),
          ],
        ),
      ),
    );
  }
}

class _RunCard extends StatelessWidget {
  const _RunCard({required this.trip, required this.enabled, required this.onOpen});

  final SchoolTrip trip;
  final bool enabled;
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final time = trip.isMorning ? trip.pickupStartsAt : trip.dropoffStartsAt;

    return GlassCard(
      strong: trip.isLive,
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
                      [trip.routeName, trip.schoolName].whereType<String>().join(' · '),
                      style: theme.textTheme.labelSmall,
                    ),
                  ],
                ),
              ),
              if (trip.isLive) const LiveDot(color: AppColors.brand400),
              const SizedBox(width: 6),
              StatusBadge(label: trip.statusLabel, colorToken: trip.statusColor),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: Format.tr('school.expected'),
                  value: Format.number(trip.expectedCount),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: Format.tr('school.picked_up'),
                  value: Format.number(trip.pickedUpCount),
                  accent: AppColors.brand300,
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
          if (time != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: [
                const Icon(Icons.schedule_rounded, size: 14, color: AppColors.ink400),
                const SizedBox(width: 4),
                Text(
                  Format.tr('school.starts_at', {'time': Format.digits(time)}),
                  style: theme.textTheme.labelSmall,
                ),
              ],
            ),
          ],
          const SizedBox(height: AppSpacing.md),
          FilledButton.icon(
            onPressed: enabled ? onOpen : null,
            icon: const Icon(Icons.list_alt_rounded, size: 18),
            label: Text(
              trip.isLive
                  ? Format.tr('school.continue_run')
                  : Format.tr('school.open_manifest'),
            ),
          ),
        ],
      ),
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.danger.withValues(alpha: 0.10),
        borderRadius: AppRadii.fieldBorder,
        border: Border.all(color: AppColors.danger.withValues(alpha: 0.28)),
      ),
      child: Row(
        children: [
          const Icon(Icons.block_rounded, size: 18, color: AppColors.danger),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: Theme.of(context).textTheme.bodySmall?.copyWith(color: AppColors.danger),
            ),
          ),
        ],
      ),
    );
  }
}
