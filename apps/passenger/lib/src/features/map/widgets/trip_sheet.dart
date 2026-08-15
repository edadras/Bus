import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../../providers.dart';

/// Detail sheet for a bus tapped on the map.
void showTripSheet(BuildContext context, LiveBus bus) {
  showModalBottomSheet<void>(
    context: context,
    backgroundColor: Colors.transparent,
    builder: (context) => _TripSheet(bus: bus),
  );
}

class _TripSheet extends ConsumerWidget {
  const _TripSheet({required this.bus});

  final LiveBus bus;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final selectedStop = ref.watch(selectedStopProvider);

    return Container(
      decoration: const BoxDecoration(
        color: AppColors.ink850,
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        border: Border(top: BorderSide(color: AppColors.glassBorder)),
      ),
      padding:
          const EdgeInsets.fromLTRB(AppSpacing.xl, AppSpacing.md, AppSpacing.xl, AppSpacing.xl),
      child: SafeArea(
        top: false,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 42,
                height: 4,
                decoration: BoxDecoration(
                  color: AppColors.ink600,
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
            ),
            const SizedBox(height: AppSpacing.xl),
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        Format.tr('trip.bus', {'number': Format.digits(bus.busNumber ?? '—')}),
                        style: theme.textTheme.titleLarge,
                      ),
                      const SizedBox(height: 3),
                      Text(
                        Format.tr('trip.line', {
                          'code': Format.digits(bus.lineCode ?? '—'),
                          'name': bus.lineName ?? ''
                        }),
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                if (bus.isOffRoute)
                  StatusBadge(label: Format.tr('trip.off_route'), colorToken: 'warning')
                else if (bus.isIdle)
                  StatusBadge(label: Format.tr('trip.idle'), colorToken: 'warning')
                else if (bus.isStale)
                  StatusBadge(label: Format.tr('trip.stale'), colorToken: 'neutral')
                else
                  StatusBadge(label: Format.tr('trip.moving'), colorToken: 'success'),
              ],
            ),
            const SizedBox(height: AppSpacing.xl),
            Row(
              children: [
                Expanded(
                  child: StatTile(
                    label: Format.tr('trip.next_stop'),
                    value: bus.nextStop ?? '—',
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: StatTile(
                    label: Format.tr('trip.eta'),
                    value: Format.minutes(bus.etaNextStopSeconds),
                    accent: AppColors.brand300,
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: [
                Expanded(
                  child: StatTile(
                    label: Format.tr('trip.passengers'),
                    value: Format.number(bus.passengerCount),
                    caption: bus.occupancy > 0
                        ? Format.tr('trip.occupancy',
                            {'percent': Format.number((bus.occupancy * 100).round())})
                        : null,
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: StatTile(
                    label: Format.tr('trip.speed'),
                    value: bus.speed == null
                        ? '—'
                        : Format.tr(
                            'trip.speed_value', {'value': Format.number(bus.speed!.round())}),
                  ),
                ),
              ],
            ),
            if (bus.destination != null) ...[
              const SizedBox(height: AppSpacing.lg),
              GlassCard(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                child: Row(
                  children: [
                    const Icon(Icons.flag_outlined, size: 18, color: AppColors.ink400),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        Format.tr('trip.destination', {'name': bus.destination}),
                        style: theme.textTheme.bodyMedium,
                      ),
                    ),
                  ],
                ),
              ),
            ],
            // "When does it reach the stop I am standing at" is the question
            // the next-stop ETA above does not answer.
            if (selectedStop != null) ...[
              const SizedBox(height: AppSpacing.lg),
              _StopEta(tripUuid: bus.tripUuid, stop: selectedStop),
            ],
            const SizedBox(height: AppSpacing.lg),
            Text(
              Format.tr('trip.last_update', {'when': Format.relative(bus.updatedAt)}),
              textAlign: TextAlign.center,
              style: theme.textTheme.labelSmall,
            ),
          ],
        ),
      ),
    );
  }
}

/// This bus's arrival at the stop the passenger is watching.
class _StopEta extends ConsumerWidget {
  const _StopEta({required this.tripUuid, required this.stop});

  final String tripUuid;
  final BusStop stop;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final eta = ref.watch(stopEtaProvider((tripUuid: tripUuid, stopId: stop.id)));
    final theme = Theme.of(context);

    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        children: [
          const Icon(Icons.schedule_rounded, size: 18, color: AppColors.ink400),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              Format.tr('trip.eta_at_stop', {'stop': stop.name}),
              style: theme.textTheme.bodySmall,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          eta.when(
            loading: () => const SizedBox(
              width: 14,
              height: 14,
              child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.brand300),
            ),
            error: (_, __) => Text('—', style: theme.textTheme.bodySmall),
            // A null estimate is an answer: the bus has already been past, or
            // the trip is not live. Saying so beats showing a dash.
            data: (value) => value == null
                ? Text(
                    Format.tr('trip.eta_passed'),
                    style: theme.textTheme.labelSmall,
                  )
                : Text(
                    Format.tr('unit.minutes', {'count': Format.number(value.minutes)}),
                    style: theme.textTheme.titleSmall?.copyWith(
                      color: value.isReliable ? AppColors.brand300 : AppColors.ink300,
                    ),
                  ),
          ),
        ],
      ),
    );
  }
}
