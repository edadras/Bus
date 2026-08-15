import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// The route ahead as a vertical timeline, with the bus's current position
/// marked. Designed to be readable in a glance from the driver's seat: large
/// type, a clear "next stop" marker, and everything already passed dimmed.
class RouteScreen extends ConsumerWidget {
  const RouteScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final route = ref.watch(driverRouteProvider);
    final theme = Theme.of(context);

    return AppScaffold(
      title: Format.tr('route.title'),
      onRefresh: () async {
        ref.invalidate(driverRouteProvider);
        await ref.read(driverRouteProvider.future);
      },
      body: route.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.info)),
        error: (error, _) => ErrorState(
          message: error is ApiException && error.code == 'no_active_trip'
              ? Format.tr('route.start_service_first')
              : Format.tr('route.load_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(driverRouteProvider),
        ),
        data: (data) {
          final stops =
              ((data['stops'] as List<dynamic>?) ?? const []).cast<Map<String, dynamic>>();

          if (stops.isEmpty) {
            return EmptyState(
              icon: Icons.route_outlined,
              message: Format.tr('route.no_stops'),
            );
          }

          return ListView.builder(
            padding: const EdgeInsets.only(bottom: 110),
            itemCount: stops.length,
            itemBuilder: (context, index) {
              final stop = stops[index];
              final passed = stop['passed'] == true;
              final isNext = stop['is_next'] == true;
              final isLast = index == stops.length - 1;

              return IntrinsicHeight(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // Timeline gutter
                    SizedBox(
                      width: 34,
                      child: Column(
                        children: [
                          Container(
                            width: isNext ? 18 : 12,
                            height: isNext ? 18 : 12,
                            margin: const EdgeInsets.only(top: 16),
                            decoration: BoxDecoration(
                              color: isNext
                                  ? AppColors.brand400
                                  : passed
                                      ? AppColors.ink600
                                      : AppColors.ink850,
                              shape: BoxShape.circle,
                              border: Border.all(
                                color: isNext
                                    ? Colors.white
                                    : passed
                                        ? AppColors.ink600
                                        : AppColors.ink500,
                                width: 2,
                              ),
                            ),
                          ),
                          if (!isLast)
                            Expanded(
                              child: Container(
                                width: 2,
                                color: passed ? AppColors.ink600 : AppColors.ink700,
                              ),
                            ),
                        ],
                      ),
                    ),

                    Expanded(
                      child: Padding(
                        padding: const EdgeInsets.only(bottom: 10),
                        child: GlassCard(
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                          borderColor: isNext ? AppColors.brand400.withValues(alpha: 0.5) : null,
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Text(
                                      stop['name'] as String? ?? '—',
                                      style: theme.textTheme.titleSmall?.copyWith(
                                        color:
                                            passed && !isNext ? AppColors.ink500 : AppColors.ink50,
                                        fontSize: isNext ? 16 : 14,
                                      ),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      Format.tr('route.stop_number', {
                                        'number':
                                            Format.number((stop['sequence'] as num?)?.toInt() ?? 0)
                                      }),
                                      style: theme.textTheme.labelSmall,
                                    ),
                                  ],
                                ),
                              ),
                              if (isNext)
                                StatusBadge(
                                    label: Format.tr('shift.next_stop'), colorToken: 'success')
                              else if (passed)
                                const Icon(Icons.check_rounded, size: 16, color: AppColors.ink500),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              );
            },
          );
        },
      ),
    );
  }
}
