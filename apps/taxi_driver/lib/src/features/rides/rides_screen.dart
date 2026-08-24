import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// Who is in the car, and what has arrived.
///
/// For a line taxi this is the whole point of the mode: fares land without a
/// conversation, so they have to land visibly. Boardings and alightings are
/// counted separately from the money — a passenger who scanned and paid is a
/// boarding, and the driver needs both numbers to know the car is full.
class TaxiRidesScreen extends ConsumerStatefulWidget {
  const TaxiRidesScreen({super.key});

  @override
  ConsumerState<TaxiRidesScreen> createState() => _TaxiRidesScreenState();
}

class _TaxiRidesScreenState extends ConsumerState<TaxiRidesScreen> {
  bool _busy = false;

  /// End a ride from the driver's side.
  ///
  /// A metered ride normally ends when the passenger says so, but the driver
  /// is the one who knows the car has stopped — and a passenger whose phone
  /// has died must not leave a meter running.
  Future<void> _endRide(TaxiRide ride) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(Format.tr('taxi.end_ride')),
        content: Text(Format.tr('taxi.end_ride_confirm')),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(Format.tr('common.cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(Format.tr('taxi.end_ride')),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    setState(() => _busy = true);

    try {
      final position = await Geolocator.getLastKnownPosition();

      final ended = await ref.read(transitApiProvider).endTaxiRideAsDriver(
            ride.uuid,
            lat: position?.latitude,
            lng: position?.longitude,
          );

      ref.invalidate(taxiRidesProvider);
      ref.invalidate(taxiStateProvider);

      if (!mounted) return;

      // The one case worth interrupting for: the wallet could not cover it,
      // so the fare is a debt rather than money the driver has taken.
      if (ended.isUnpaid) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              Format.tr('taxi.ride_unpaid', {'amount': Format.money(ended.outstandingAmount)}),
            ),
          ),
        );
      }
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
    final rides = ref.watch(taxiRidesProvider);
    final shiftUuid = ref.watch(taxiStateProvider).valueOrNull?.shift?.uuid;
    final arrival = ref.watch(taxiFareArrivalProvider);
    final theme = Theme.of(context);

    // Subscribing here rather than in the shell: the feed is only worth
    // holding open while somebody is looking at the figures it updates.
    if (shiftUuid != null) ref.watch(taxiShiftFeedProvider(shiftUuid));

    return AppScaffold(
      title: Format.tr('taxi.rides_title'),
      onRefresh: () async {
        ref.invalidate(taxiRidesProvider);
        await ref.read(taxiRidesProvider.future);
      },
      body: rides.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.warning)),
        error: (error, _) => ErrorState(
          message: error is ApiException && error.code == 'no_open_shift'
              ? Format.tr('taxi.rides_need_shift')
              : error is ApiException
                  ? error.message
                  : Format.tr('taxi.rides_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(taxiRidesProvider),
        ),
        data: (view) => ListView(
          padding: const EdgeInsets.only(bottom: 110),
          children: [
            if (arrival != null) ...[
              _FareArrival(arrival: arrival),
              const SizedBox(height: AppSpacing.md),
            ],
            if (view.shift != null) _Counters(shift: view.shift!),
            const SizedBox(height: AppSpacing.lg),
            Text(Format.tr('taxi.onboard_now'), style: theme.textTheme.titleSmall),
            const SizedBox(height: AppSpacing.sm),
            if (view.onboard.isEmpty)
              EmptyState(
                icon: Icons.airline_seat_recline_normal_rounded,
                message: Format.tr('taxi.nobody_aboard'),
              )
            else
              for (final ride in view.onboard)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: _RideTile(
                    ride: ride,
                    onEnd: _busy ? null : () => _endRide(ride),
                  ),
                ),
            const SizedBox(height: AppSpacing.lg),
            Text(Format.tr('taxi.recent_rides'), style: theme.textTheme.titleSmall),
            const SizedBox(height: AppSpacing.sm),
            if (view.recent.isEmpty)
              EmptyState(
                icon: Icons.receipt_long_outlined,
                message: Format.tr('taxi.no_rides_yet'),
              )
            else
              for (final ride in view.recent)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: _RideTile(ride: ride, onEnd: null),
                ),
          ],
        ),
      ),
    );
  }
}

/// The last fare to land.
///
/// Announced by the server the moment the passenger's wallet is debited, which
/// is the whole point of the fixed-fare mode from the driver's side: money
/// arrives in a wallet they cannot see being credited, so the app has to say
/// so rather than leave them to notice a counter move.
class _FareArrival extends StatelessWidget {
  const _FareArrival({required this.arrival});

  final TaxiFareArrival arrival;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      strong: true,
      child: Row(
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              color: AppColors.brand500.withValues(alpha: 0.16),
              borderRadius: BorderRadius.circular(14),
            ),
            child: const Icon(Icons.payments_rounded, color: AppColors.brand300),
          ),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(Format.tr('taxi.fare_arrived'), style: theme.textTheme.labelMedium),
                Text(
                  arrival.formattedAmount,
                  style: theme.textTheme.titleLarge?.copyWith(color: AppColors.brand300),
                ),
              ],
            ),
          ),
          const LiveDot(),
        ],
      ),
    );
  }
}

class _Counters extends StatelessWidget {
  const _Counters({required this.shift});

  final TaxiShift shift;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      strong: true,
      child: Column(
        children: [
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: Format.tr('taxi.boardings'),
                  value: Format.number(shift.boardingCount),
                  accent: AppColors.brand300,
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: Format.tr('taxi.alightings'),
                  value: Format.number(shift.alightingCount),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: Format.tr('taxi.onboard'),
                  value: Format.number(shift.onboardCount),
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          Row(
            children: [
              Expanded(
                child: StatTile(
                  label: Format.tr('taxi.rides'),
                  value: Format.number(shift.rideCount),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: StatTile(
                  label: Format.tr('taxi.gross'),
                  value: shift.formattedGross,
                  accent: AppColors.brand300,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _RideTile extends StatelessWidget {
  const _RideTile({required this.ride, required this.onEnd});

  final TaxiRide ride;
  final VoidCallback? onEnd;

  Color get _color => switch (ride.serviceType) {
        TaxiServiceType.line => AppColors.brand500,
        TaxiServiceType.charter => AppColors.warning,
        TaxiServiceType.meter => AppColors.info,
      };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final fare = ride.currentFare?.formattedAmount ?? ride.formattedFare;

    return GlassCard(
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Container(
                width: 8,
                height: 8,
                decoration: BoxDecoration(color: _color, shape: BoxShape.circle),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      ride.serviceType == TaxiServiceType.line && ride.lineName != null
                          ? ride.lineName!
                          : ride.serviceType.label,
                      style: theme.textTheme.titleSmall,
                    ),
                    Text(
                      ride.startedAt == null ? '—' : Format.time(ride.startedAt),
                      style: theme.textTheme.labelSmall,
                    ),
                  ],
                ),
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    ride.isUnpaid ? Format.money(ride.outstandingAmount) : fare,
                    style: theme.textTheme.titleSmall?.copyWith(
                      color: ride.isUnpaid ? AppColors.danger : AppColors.brand300,
                    ),
                  ),
                  StatusBadge(label: ride.statusLabel, colorToken: ride.statusColor),
                ],
              ),
            ],
          ),
          if (ride.serviceType == TaxiServiceType.meter && ride.isOpen) ...[
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: [
                const Icon(Icons.straighten_rounded, size: 14, color: AppColors.ink400),
                const SizedBox(width: 4),
                Text(
                  Format.distance(ride.currentFare?.distanceMeters ?? ride.distanceMeters),
                  style: theme.textTheme.labelSmall,
                ),
                const SizedBox(width: AppSpacing.md),
                const Icon(Icons.timer_outlined, size: 14, color: AppColors.ink400),
                const SizedBox(width: 4),
                Text(
                  Format.duration(ride.currentFare?.waitingSeconds ?? ride.waitingSeconds),
                  style: theme.textTheme.labelSmall,
                ),
              ],
            ),
          ],
          if (onEnd != null && ride.isOpen) ...[
            const SizedBox(height: AppSpacing.sm),
            OutlinedButton.icon(
              onPressed: onEnd,
              icon: const Icon(Icons.flag_rounded, size: 16),
              label: Text(Format.tr('taxi.end_ride')),
            ),
          ],
        ],
      ),
    );
  }
}
