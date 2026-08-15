import 'package:flutter/material.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// One suggested journey, shown as the itinerary a passenger would actually
/// follow: walk here, take this line, change there, walk to the destination.
///
/// Every figure on this card is an estimate that includes the expected wait at
/// the stop, and the card says so — a headline number presented as a timetable
/// is how a rider misses a bus and stops trusting the app.
class JourneyOptionCard extends StatelessWidget {
  const JourneyOptionCard({required this.option, this.rank = 0, super.key});

  final JourneyOption option;

  /// Position in the list; the first one gets the accent treatment.
  final int rank;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      strong: rank == 0,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      Format.tr('plan.total_minutes', {
                        'count': Format.number(option.totalMinutes),
                      }),
                      style: theme.textTheme.headlineMedium?.copyWith(
                        color: rank == 0 ? AppColors.brand300 : AppColors.ink100,
                        height: 1.1,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      Format.tr('plan.walk_summary', {
                        'meters': Format.number(option.totalWalkMeters),
                      }),
                      style: theme.textTheme.labelSmall,
                    ),
                  ],
                ),
              ),
              StatusBadge(
                label: option.isWalk
                    ? Format.tr('plan.walk_option')
                    : option.transfers == 0
                        ? Format.tr('plan.direct')
                        : Format.tr('plan.transfers', {
                            'count': Format.number(option.transfers),
                          }),
                colorToken: option.isWalk
                    ? 'neutral'
                    : option.transfers == 0
                        ? 'success'
                        : 'warning',
              ),
            ],
          ),
          if (!option.isWalk) ...[
            const SizedBox(height: AppSpacing.lg),
            if (option.walkToStopMeters > 0)
              _WalkRow(
                label: Format.tr('plan.walk_to_stop', {
                  'meters': Format.number(option.walkToStopMeters),
                }),
              ),
            for (var index = 0; index < option.legs.length; index++) ...[
              _LegRow(leg: option.legs[index], isLast: index == option.legs.length - 1),
            ],
            if (option.walkFromStopMeters > 0)
              _WalkRow(
                label: Format.tr('plan.walk_from_stop', {
                  'meters': Format.number(option.walkFromStopMeters),
                }),
              ),
          ],
          const SizedBox(height: AppSpacing.md),
          Text(
            Format.tr('plan.estimate_note'),
            style: theme.textTheme.labelSmall?.copyWith(color: AppColors.ink500, fontSize: 10),
          ),
        ],
      ),
    );
  }
}

class _WalkRow extends StatelessWidget {
  const _WalkRow({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          const SizedBox(
            width: 34,
            child: Icon(Icons.directions_walk_rounded, size: 18, color: AppColors.ink400),
          ),
          Expanded(
            child: Text(label, style: Theme.of(context).textTheme.bodySmall),
          ),
        ],
      ),
    );
  }
}

class _LegRow extends StatelessWidget {
  const _LegRow({required this.leg, required this.isLast});

  final JourneyLeg leg;
  final bool isLast;

  Color get _lineColor {
    final hex = leg.lineColor?.replaceAll('#', '');

    if (hex == null || hex.length != 6) return AppColors.brand500;

    return Color(int.parse('FF$hex', radix: 16));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 34,
            child: Container(
              width: 30,
              height: 30,
              decoration: BoxDecoration(
                color: _lineColor,
                borderRadius: BorderRadius.circular(10),
              ),
              alignment: Alignment.center,
              child: Text(
                Format.digits(leg.lineCode),
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: Colors.white,
                ),
              ),
            ),
          ),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                if (leg.lineName.isNotEmpty)
                  Text(
                    leg.lineName,
                    style: theme.textTheme.titleSmall,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                const SizedBox(height: 2),
                Text(
                  Format.tr('plan.board_at', {'stop': leg.boardStopName}),
                  style: theme.textTheme.bodySmall,
                ),
                Text(
                  Format.tr('plan.alight_at', {'stop': leg.alightStopName}),
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: 2),
                Row(
                  children: [
                    Text(
                      Format.tr('plan.leg_stops', {
                        'count': Format.number(leg.stopsCount),
                        'minutes': Format.number(leg.rideMinutes),
                      }),
                      style: theme.textTheme.labelSmall,
                    ),
                    if (leg.headwayMinutes != null) ...[
                      const SizedBox(width: 8),
                      Text(
                        Format.tr('plan.headway_note', {
                          'count': Format.number(leg.headwayMinutes),
                        }),
                        style: theme.textTheme.labelSmall,
                      ),
                    ],
                  ],
                ),
                // The change itself is where a journey goes wrong, so it gets
                // its own line rather than being implied by two legs meeting.
                if (!isLast) ...[
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      const Icon(Icons.swap_calls_rounded, size: 14, color: AppColors.warning),
                      const SizedBox(width: 6),
                      Text(
                        Format.tr('plan.transfers', {'count': Format.number(1)}),
                        style: theme.textTheme.labelSmall?.copyWith(color: AppColors.warning),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
