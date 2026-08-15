import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';
import 'widgets/endpoint_picker.dart';
import 'widgets/journey_option_card.dart';

/// Journey planning: pick two points, get the combinations of lines that get
/// you between them.
///
/// The screen is usable without an account — planning a trip is exactly the
/// thing someone does before deciding whether to sign up.
class PlanScreen extends ConsumerWidget {
  const PlanScreen({super.key});

  Future<void> _pick(
    BuildContext context,
    WidgetRef ref, {
    required bool isOrigin,
  }) async {
    final endpoint = await showEndpointPicker(
      context,
      title: Format.tr(isOrigin ? 'plan.from' : 'plan.to'),
    );

    if (endpoint == null) return;

    final target = isOrigin ? journeyOriginProvider : journeyDestinationProvider;
    ref.read(target.notifier).state = endpoint;

    // The previous answer described a different journey; keeping it on screen
    // under new endpoints would be a lie.
    ref.read(journeyPlanProvider.notifier).clear();
  }

  void _swap(WidgetRef ref) {
    final origin = ref.read(journeyOriginProvider);
    final destination = ref.read(journeyDestinationProvider);

    ref.read(journeyOriginProvider.notifier).state = destination;
    ref.read(journeyDestinationProvider.notifier).state = origin;
    ref.read(journeyPlanProvider.notifier).clear();
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final origin = ref.watch(journeyOriginProvider);
    final destination = ref.watch(journeyDestinationProvider);
    final plan = ref.watch(journeyPlanProvider);
    final isSearching = plan is AsyncLoading;

    return AppScaffold(
      title: Format.tr('plan.title'),
      body: ListView(
        padding: const EdgeInsets.only(top: AppSpacing.md, bottom: 90),
        children: [
          GlassCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Column(
                        children: [
                          _EndpointField(
                            icon: Icons.trip_origin_rounded,
                            label: Format.tr('plan.from'),
                            value: origin?.label,
                            onTap: () => _pick(context, ref, isOrigin: true),
                          ),
                          const SizedBox(height: AppSpacing.sm),
                          _EndpointField(
                            icon: Icons.place_rounded,
                            label: Format.tr('plan.to'),
                            value: destination?.label,
                            onTap: () => _pick(context, ref, isOrigin: false),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    IconButton(
                      tooltip: Format.tr('plan.swap'),
                      onPressed: () => _swap(ref),
                      icon: const Icon(Icons.swap_vert_rounded),
                      color: AppColors.brand300,
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.lg),
                const _TransferChips(),
                const SizedBox(height: AppSpacing.lg),
                FilledButton.icon(
                  onPressed: isSearching || origin == null || destination == null
                      ? null
                      : () => ref.read(journeyPlanProvider.notifier).search(),
                  icon: isSearching
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                        )
                      : const Icon(Icons.directions_rounded, size: 18),
                  label: Text(
                    Format.tr(isSearching ? 'plan.searching' : 'plan.search'),
                  ),
                ),
                if (origin == null || destination == null) ...[
                  const SizedBox(height: AppSpacing.sm),
                  Text(
                    Format.tr('plan.pick_both'),
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.labelSmall,
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          _Results(plan: plan),
        ],
      ),
    );
  }
}

class _Results extends ConsumerWidget {
  const _Results({required this.plan});

  final AsyncValue<JourneyPlan>? plan;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (plan == null) {
      return EmptyState(
        icon: Icons.alt_route_rounded,
        message: Format.tr('plan.intro'),
      );
    }

    return plan!.when(
      loading: () => Column(
        children: const [
          ShimmerBox(height: 132),
          SizedBox(height: AppSpacing.md),
          ShimmerBox(height: 132),
        ],
      ),
      error: (error, _) => ErrorState(
        message: error is ApiException ? error.message : Format.tr('plan.failed'),
        isOffline: error is NetworkException,
        onRetry: () => ref.read(journeyPlanProvider.notifier).search(),
      ),
      data: (result) {
        if (result.options.isEmpty) {
          return EmptyState(
            icon: Icons.wrong_location_outlined,
            // The planner says *why* it found nothing, and the two reasons
            // call for different actions: move the pin, or accept a change.
            message: Format.tr(
              result.reason == 'no_stop_within_walking_distance'
                  ? 'plan.no_stop_within_walking_distance'
                  : 'plan.no_route_found',
            ),
          );
        }

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm, right: 4, left: 4),
              child: Text(
                Format.tr('plan.results_count', {
                  'count': Format.number(result.options.length),
                }),
                style: Theme.of(context).textTheme.titleSmall,
              ),
            ),
            for (var index = 0; index < result.options.length; index++) ...[
              JourneyOptionCard(option: result.options[index], rank: index),
              const SizedBox(height: AppSpacing.md),
            ],
          ],
        );
      },
    );
  }
}

class _EndpointField extends StatelessWidget {
  const _EndpointField({
    required this.icon,
    required this.label,
    required this.value,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final String? value;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return InkWell(
      onTap: onTap,
      borderRadius: AppRadii.fieldBorder,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
        decoration: BoxDecoration(
          color: AppColors.ink800,
          borderRadius: AppRadii.fieldBorder,
          border: Border.all(color: AppColors.glassBorder),
        ),
        child: Row(
          children: [
            Icon(icon, size: 16, color: AppColors.brand300),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(label, style: theme.textTheme.labelSmall),
                  const SizedBox(height: 1),
                  Text(
                    value ?? Format.tr('plan.choose_stop'),
                    style: value == null
                        ? theme.textTheme.bodySmall?.copyWith(color: AppColors.ink500)
                        : theme.textTheme.titleSmall,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
              ),
            ),
            const Icon(Icons.expand_more_rounded, size: 18, color: AppColors.ink400),
          ],
        ),
      ),
    );
  }
}

/// How many changes the passenger will put up with. Narrowing this is the one
/// knob that reliably changes the answer, so it sits next to the button.
class _TransferChips extends ConsumerWidget {
  const _TransferChips();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final selected = ref.watch(journeyMaxTransfersProvider);

    const options = <int, String>{
      2: 'plan.transfers_any',
      1: 'plan.transfers_one',
      0: 'plan.transfers_direct',
    };

    return Row(
      children: [
        Text(Format.tr('plan.max_transfers'), style: Theme.of(context).textTheme.labelSmall),
        const SizedBox(width: AppSpacing.sm),
        Expanded(
          child: Wrap(
            spacing: AppSpacing.sm,
            runSpacing: AppSpacing.xs,
            children: [
              for (final entry in options.entries)
                ChoiceChip(
                  label: Text(Format.tr(entry.value)),
                  selected: selected == entry.key,
                  onSelected: (_) {
                    ref.read(journeyMaxTransfersProvider.notifier).state = entry.key;
                    ref.read(journeyPlanProvider.notifier).clear();
                  },
                ),
            ],
          ),
        ),
      ],
    );
  }
}
