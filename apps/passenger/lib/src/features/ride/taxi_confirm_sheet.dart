import 'package:flutter/material.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// What this ride costs, before it is taken.
///
/// The whole point of the two-step scan: the passenger sees the figure, and
/// the figure they saw is what goes back to the server. A charter price the
/// driver changed in the two seconds since the scan will not match, and the
/// server refuses it rather than charging the new one.
///
/// A metered ride has no figure yet. What is shown instead is the tariff — the
/// base fare, the rate per kilometre, and the rate for waiting — because a
/// meter the passenger cannot check in advance is only fair if the arithmetic
/// is on the screen before it starts.
class TaxiConfirmSheet extends StatelessWidget {
  const TaxiConfirmSheet({required this.quote, super.key});

  final TaxiScanResult quote;

  Color get _color => switch (quote.serviceType) {
        TaxiServiceType.line => AppColors.brand500,
        TaxiServiceType.charter => AppColors.warning,
        TaxiServiceType.meter => AppColors.info,
      };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final breakdown = quote.quote.breakdown;

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
            Row(
              children: [
                Container(
                  width: 42,
                  height: 42,
                  decoration: BoxDecoration(
                    color: _color.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Icon(Icons.local_taxi_rounded, color: _color),
                ),
                const SizedBox(width: AppSpacing.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(quote.serviceType.label, style: theme.textTheme.titleMedium),
                      Text(
                        Format.digits(
                          [
                            Format.tr('taxi_ride.car', {'number': quote.taxiNumber}),
                            if (quote.plate != null) quote.plate!,
                          ].join(' · '),
                        ),
                        style: theme.textTheme.labelSmall,
                      ),
                    ],
                  ),
                ),
              ],
            ),

            if (quote.line != null) ...[
              const SizedBox(height: AppSpacing.md),
              _Row(
                label: Format.tr('taxi_ride.line'),
                value: Format.digits(
                  '${quote.line!.origin ?? ''} → ${quote.line!.destination ?? quote.line!.name}',
                ),
              ),
            ],

            const SizedBox(height: AppSpacing.lg),

            if (quote.serviceType.isPricedUpFront) ...[
              Center(
                child: Column(
                  children: [
                    Text(Format.tr('taxi_ride.you_pay'), style: theme.textTheme.labelMedium),
                    const SizedBox(height: 4),
                    Text(
                      quote.quote.formattedAmount,
                      style: theme.textTheme.displaySmall?.copyWith(color: _color),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              Text(
                Format.tr('taxi_ride.confirm_body.${quote.serviceType.value}'),
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall,
              ),
            ] else ...[
              Text(Format.tr('taxi_ride.tariff'), style: theme.textTheme.titleSmall),
              const SizedBox(height: AppSpacing.sm),
              if (breakdown['base_fare'] is num)
                _Row(
                  label: Format.tr('taxi_ride.base_fare'),
                  value: Format.money((breakdown['base_fare'] as num).toInt()),
                ),
              if (breakdown['per_km_fare'] is num)
                _Row(
                  label: Format.tr('taxi_ride.per_km'),
                  value: Format.money((breakdown['per_km_fare'] as num).toInt()),
                ),
              if (breakdown['per_minute_waiting_fare'] is num)
                _Row(
                  label: Format.tr('taxi_ride.per_minute_waiting'),
                  value: Format.money((breakdown['per_minute_waiting_fare'] as num).toInt()),
                ),
              if (breakdown['minimum_fare'] is num)
                _Row(
                  label: Format.tr('taxi_ride.minimum_fare'),
                  value: Format.money((breakdown['minimum_fare'] as num).toInt()),
                ),
              const SizedBox(height: AppSpacing.md),
              Text(
                Format.tr('taxi_ride.confirm_body.meter'),
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall,
              ),
            ],

            const SizedBox(height: AppSpacing.lg),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.pop(context, false),
                    child: Text(Format.tr('common.cancel')),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  flex: 2,
                  child: FilledButton(
                    onPressed: () => Navigator.pop(context, true),
                    style: FilledButton.styleFrom(backgroundColor: _color),
                    child: Text(
                      quote.serviceType.isOpenEnded
                          ? Format.tr('taxi_ride.start_meter')
                          : Format.tr('taxi_ride.pay_and_ride'),
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(child: Text(label, style: theme.textTheme.bodySmall)),
          Text(value, style: theme.textTheme.titleSmall),
        ],
      ),
    );
  }
}
