import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// The live passenger counter.
///
/// Note what is deliberately absent: passenger names, numbers or positions.
/// The driver needs the count and the fact that a payment landed, nothing more,
/// so the endpoint returns only aggregate figures and anonymous boarding times.
class PassengersScreen extends ConsumerWidget {
  const PassengersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final passengers = ref.watch(passengersProvider);
    final theme = Theme.of(context);

    return AppScaffold(
      title: Format.tr('passengers.title'),
      subtitle: Format.tr('passengers.subtitle'),
      onRefresh: () async {
        ref.invalidate(passengersProvider);
        await ref.read(passengersProvider.future);
      },
      body: passengers.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.info)),
        error: (error, _) => ErrorState(
          message: error is ApiException && error.code == 'no_active_trip'
              ? Format.tr('passengers.no_active_trip')
              : Format.tr('passengers.load_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(passengersProvider),
        ),
        data: (data) {
          final count = (data['passenger_count'] as num?)?.toInt() ?? 0;
          final capacity = (data['capacity'] as num?)?.toInt() ?? 0;
          final occupancy = (data['occupancy'] as num?)?.toDouble() ?? 0;
          final boardings = ((data['recent_boardings'] as List<dynamic>?) ?? const [])
              .cast<Map<String, dynamic>>();

          return ListView(
            padding: const EdgeInsets.only(bottom: 110),
            children: [
              GlassCard(
                strong: true,
                padding: const EdgeInsets.all(AppSpacing.xl),
                child: Column(
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const LiveDot(),
                        const SizedBox(width: 8),
                        Text(Format.tr('passengers.on_board'), style: theme.textTheme.labelMedium),
                      ],
                    ),
                    const SizedBox(height: AppSpacing.lg),
                    Text(
                      Format.number(count),
                      style: theme.textTheme.displayLarge?.copyWith(
                        fontSize: 64,
                        color: AppColors.brand300,
                        height: 1,
                      ),
                    ),
                    const SizedBox(height: AppSpacing.sm),
                    Text(Format.tr('passengers.people'), style: theme.textTheme.bodySmall),
                    if (capacity > 0) ...[
                      const SizedBox(height: AppSpacing.xl),
                      ClipRRect(
                        borderRadius: BorderRadius.circular(999),
                        child: LinearProgressIndicator(
                          value: occupancy.clamp(0.0, 1.0),
                          minHeight: 8,
                          backgroundColor: AppColors.ink700,
                          // Turns amber then red as the bus fills, so crowding
                          // is legible at a glance from the driver's seat.
                          color: occupancy > 0.9
                              ? AppColors.danger
                              : occupancy > 0.7
                                  ? AppColors.warning
                                  : AppColors.brand400,
                        ),
                      ),
                      const SizedBox(height: AppSpacing.sm),
                      Text(
                        Format.tr('passengers.occupancy', {
                          'percent': Format.number((occupancy * 100).round()),
                          'capacity': Format.number(capacity),
                        }),
                        style: theme.textTheme.labelSmall,
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: Format.tr('passengers.total_boardings'),
                      value: Format.number((data['boarding_count'] as num?)?.toInt() ?? 0),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: StatTile(
                      label: Format.tr('passengers.peak'),
                      value: Format.number((data['peak_passenger_count'] as num?)?.toInt() ?? 0),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: StatTile(
                      label: Format.tr('passengers.trip_revenue'),
                      value: (data['revenue'] as Map<String, dynamic>?)?['formatted'] as String? ??
                          '—',
                      accent: AppColors.brand300,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.lg),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: Text(Format.tr('passengers.recent_payments'),
                    style: theme.textTheme.titleSmall),
              ),
              const SizedBox(height: AppSpacing.sm),
              if (boardings.isEmpty)
                EmptyState(
                  icon: Icons.confirmation_number_outlined,
                  message: Format.tr('passengers.none_yet'),
                )
              else
                for (final boarding in boardings) ...[
                  GlassCard(
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    child: Row(
                      children: [
                        Container(
                          width: 34,
                          height: 34,
                          decoration: BoxDecoration(
                            color: AppColors.brand500.withValues(alpha: 0.15),
                            borderRadius: BorderRadius.circular(11),
                          ),
                          child: const Icon(Icons.person_add_alt_1_rounded,
                              size: 16, color: AppColors.brand300),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Text(
                            Format.tr('passengers.boarded'),
                            style: theme.textTheme.titleSmall,
                          ),
                        ),
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.end,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(
                              Format.money((boarding['fare'] as num?)?.toInt()),
                              style: theme.textTheme.titleSmall?.copyWith(
                                color: AppColors.brand300,
                              ),
                            ),
                            Text(
                              Format.time(
                                boarding['boarded_at'] == null
                                    ? null
                                    : DateTime.tryParse(boarding['boarded_at'] as String),
                              ),
                              style: theme.textTheme.labelSmall?.copyWith(fontSize: 10),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                ],
            ],
          );
        },
      ),
    );
  }
}
