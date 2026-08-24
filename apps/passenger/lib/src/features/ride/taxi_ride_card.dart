import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// The taxi ride in progress.
///
/// For a metered ride this is the price as it stands right now, refreshed
/// every few seconds: a fare that only appears at the destination is a
/// surprise, not a price. The distance behind it is the car's, not this
/// phone's — the phone can be in a bag, or off, without changing the bill.
class TaxiRideCard extends ConsumerStatefulWidget {
  const TaxiRideCard({required this.ride, super.key});

  final TaxiRide ride;

  @override
  ConsumerState<TaxiRideCard> createState() => _TaxiRideCardState();
}

class _TaxiRideCardState extends ConsumerState<TaxiRideCard> {
  bool _busy = false;

  Color get _color => switch (widget.ride.serviceType) {
        TaxiServiceType.line => AppColors.brand500,
        TaxiServiceType.charter => AppColors.warning,
        TaxiServiceType.meter => AppColors.info,
      };

  Future<void> _end() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(Format.tr('taxi_ride.end_title')),
        content: Text(
          widget.ride.serviceType.isOpenEnded
              ? Format.tr('taxi_ride.end_meter_body')
              : Format.tr('taxi_ride.end_body'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(Format.tr('common.cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(Format.tr('taxi_ride.end_action')),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    setState(() => _busy = true);

    try {
      final position = await ref.read(devicePositionProvider.future);

      final ended = await ref.read(transitApiProvider).endTaxiRide(
            widget.ride.uuid,
            lat: position?.latitude,
            lng: position?.longitude,
          );

      ref
        ..invalidate(activeTaxiRideProvider)
        ..invalidate(taxiHistoryProvider)
        ..invalidate(walletProvider)
        ..invalidate(walletTransactionsProvider);

      if (!mounted) return;

      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          icon: Icon(
            ended.isUnpaid ? Icons.error_outline_rounded : Icons.check_circle_rounded,
            color: ended.isUnpaid ? AppColors.warning : AppColors.brand400,
            size: 44,
          ),
          title: Text(
            ended.isUnpaid
                ? Format.tr('taxi_ride.unpaid_title')
                : Format.tr('taxi_ride.paid_title'),
          ),
          content: Text(
            ended.isUnpaid
                ? Format.tr('taxi_ride.unpaid_body', {
                    'amount': Format.money(ended.outstandingAmount),
                  })
                : Format.tr('scan.fare_charged', {'amount': ended.formattedFare}),
            textAlign: TextAlign.center,
          ),
          actions: [
            FilledButton(
              onPressed: () => Navigator.pop(context),
              child: Text(Format.tr('scan.ok')),
            ),
          ],
        ),
      );
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
    final theme = Theme.of(context);
    final ride = widget.ride;
    final meter = ride.currentFare;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        GlassCard(
          strong: true,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  LiveDot(color: _color),
                  const SizedBox(width: 8),
                  Text(ride.serviceType.label, style: theme.textTheme.titleSmall),
                  const Spacer(),
                  StatusBadge(label: ride.statusLabel, colorToken: ride.statusColor),
                ],
              ),
              const SizedBox(height: AppSpacing.lg),

              if (ride.serviceType.isOpenEnded) ...[
                Center(
                  child: Column(
                    children: [
                      Text(Format.tr('taxi_ride.running_total'),
                          style: theme.textTheme.labelMedium),
                      const SizedBox(height: 4),
                      Text(
                        meter?.formattedAmount ?? ride.formattedFare,
                        style: theme.textTheme.displaySmall?.copyWith(color: _color),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                Row(
                  children: [
                    Expanded(
                      child: StatTile(
                        label: Format.tr('taxi_ride.distance'),
                        value: Format.distance(meter?.distanceMeters ?? ride.distanceMeters),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: StatTile(
                        label: Format.tr('taxi_ride.waiting'),
                        value: Format.duration(meter?.waitingSeconds ?? ride.waitingSeconds),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  Format.tr('taxi_ride.meter_source'),
                  style: theme.textTheme.labelSmall,
                ),
              ] else ...[
                Center(
                  child: Column(
                    children: [
                      Text(Format.tr('taxi_ride.fare_paid'), style: theme.textTheme.labelMedium),
                      const SizedBox(height: 4),
                      Text(
                        ride.formattedFare,
                        style: theme.textTheme.displaySmall?.copyWith(color: _color),
                      ),
                    ],
                  ),
                ),
                if (ride.lineName != null) ...[
                  const SizedBox(height: AppSpacing.md),
                  Text(
                    ride.lineName!,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodySmall,
                  ),
                ],
              ],

              const SizedBox(height: AppSpacing.lg),
              Row(
                children: [
                  const Icon(Icons.local_taxi_outlined, size: 16, color: AppColors.ink400),
                  const SizedBox(width: 6),
                  Text(
                    Format.digits(
                      [
                        Format.tr('taxi_ride.car', {'number': ride.taxiNumber ?? '—'}),
                        if (ride.plate != null) ride.plate!,
                      ].join(' · '),
                    ),
                    style: theme.textTheme.labelSmall,
                  ),
                  const Spacer(),
                  if (ride.driverName != null)
                    Text(ride.driverName!, style: theme.textTheme.labelSmall),
                ],
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.md),

        // Only a meter needs stopping. A line or charter fare is already paid,
        // and the ride closes when the driver's shift does.
        if (ride.serviceType.isOpenEnded)
          FilledButton.icon(
            onPressed: _busy ? null : _end,
            style: FilledButton.styleFrom(backgroundColor: _color),
            icon: const Icon(Icons.flag_rounded, size: 18),
            label: Text(Format.tr('taxi_ride.end_action')),
          )
        else
          OutlinedButton.icon(
            onPressed: _busy ? null : _end,
            icon: const Icon(Icons.logout_rounded, size: 18),
            label: Text(Format.tr('taxi_ride.i_got_off')),
          ),
      ],
    );
  }
}
