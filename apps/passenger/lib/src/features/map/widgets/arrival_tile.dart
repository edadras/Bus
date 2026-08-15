import 'package:flutter/material.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// One row of the arrival board.
///
/// The confidence flag matters here: a low-confidence estimate is shown in a
/// muted colour and labelled «تقریبی», because publishing an uncertain figure
/// as though it were firm is how riders stop trusting the board entirely.
class ArrivalTile extends StatelessWidget {
  const ArrivalTile({required this.arrival, this.onTap, super.key});

  final Arrival arrival;
  final VoidCallback? onTap;

  Color get _lineColor {
    final hex = arrival.lineColor?.replaceAll('#', '');

    if (hex == null || hex.length != 6) return AppColors.brand500;

    return Color(int.parse('FF$hex', radix: 16));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: _lineColor,
              borderRadius: BorderRadius.circular(13),
            ),
            alignment: Alignment.center,
            child: Text(
              Format.digits(arrival.lineCode ?? '—'),
              style: const TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                color: Colors.white,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  arrival.destination ?? arrival.lineName ?? 'مقصد نامشخص',
                  style: theme.textTheme.titleSmall,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 3),
                Row(
                  children: [
                    Text(
                      'اتوبوس ${Format.digits(arrival.busNumber ?? '—')}',
                      style: theme.textTheme.labelSmall,
                    ),
                    const SizedBox(width: 8),
                    Icon(Icons.person_outline_rounded, size: 12, color: AppColors.ink500),
                    const SizedBox(width: 2),
                    Text(
                      Format.number(arrival.passengerCount),
                      style: theme.textTheme.labelSmall,
                    ),
                    if (arrival.stopsAway > 0) ...[
                      const SizedBox(width: 8),
                      Text(
                        '${Format.number(arrival.stopsAway)} ایستگاه',
                        style: theme.textTheme.labelSmall,
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                Format.etaMinutes(arrival.etaSeconds),
                style: theme.textTheme.headlineMedium?.copyWith(
                  color: arrival.isReliable ? AppColors.brand300 : AppColors.ink300,
                  height: 1,
                ),
              ),
              Text('دقیقه', style: theme.textTheme.labelSmall),
              if (!arrival.isReliable)
                Text(
                  'تقریبی',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: AppColors.warning,
                    fontSize: 9,
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}
