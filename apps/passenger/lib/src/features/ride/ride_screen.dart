import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';
import 'scan_screen.dart';

/// The ride tab: either the in-progress journey, or the entry point to scan
/// and pay. It refreshes on a timer while a ride is open so the passenger sees
/// the same passenger count and next stop the driver does.
class RideScreen extends ConsumerStatefulWidget {
  const RideScreen({super.key});

  @override
  ConsumerState<RideScreen> createState() => _RideScreenState();
}

class _RideScreenState extends ConsumerState<RideScreen> {
  Timer? _refreshTimer;
  Timer? _pingTimer;
  bool _sharingLocation = false;

  @override
  void initState() {
    super.initState();

    _refreshTimer = Timer.periodic(
      const Duration(seconds: 15),
      (_) => ref.invalidate(activeRideProvider),
    );
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    _pingTimer?.cancel();
    super.dispose();
  }

  /// Opt-in position sharing, used only to notice that the passenger has got
  /// off. It stops the moment the ride closes, and the server prunes the
  /// samples within a day.
  void _toggleLocationSharing(ActiveRide ride) {
    if (_sharingLocation) {
      _pingTimer?.cancel();
      setState(() => _sharingLocation = false);

      return;
    }

    setState(() => _sharingLocation = true);

    _pingTimer = Timer.periodic(const Duration(seconds: 25), (_) async {
      final position = await ref.read(devicePositionProvider.future);

      if (position == null) return;

      try {
        final result = await ref.read(transitApiProvider).reportRidePosition(
              rideUuid: ride.uuid,
              lat: position.latitude,
              lng: position.longitude,
              accuracy: position.accuracy,
            );

        if (result['closed'] == true) {
          _pingTimer?.cancel();
          ref.invalidate(activeRideProvider);
          ref.invalidate(walletProvider);
        }
      } catch (_) {
        // A failed sample is not worth surfacing; the next one will retry.
      }
    });
  }

  Future<void> _endRide(ActiveRide ride) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('پایان سفر'),
        content: const Text('آیا از اتوبوس پیاده شده‌اید؟'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('خیر'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('بله، پیاده شدم'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    final position = await ref.read(devicePositionProvider.future);

    try {
      await ref.read(transitApiProvider).endRide(
            ride.uuid,
            lat: position?.latitude,
            lng: position?.longitude,
          );

      _pingTimer?.cancel();

      if (!mounted) return;

      setState(() => _sharingLocation = false);
      ref.invalidate(activeRideProvider);
      ref.invalidate(rideHistoryProvider);

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('سفر شما پایان یافت. سفر بخیر!')),
      );
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final ride = ref.watch(activeRideProvider);

    return AppScaffold(
      title: 'سفر',
      onRefresh: () async {
        ref.invalidate(activeRideProvider);
        await ref.read(activeRideProvider.future);
      },
      body: ride.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.brand400)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : 'دریافت اطلاعات سفر ممکن نشد.',
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(activeRideProvider),
        ),
        data: (active) => ListView(
          padding: const EdgeInsets.only(bottom: 100),
          children: [
            if (active != null)
              _ActiveRideCard(
                ride: active,
                sharingLocation: _sharingLocation,
                onToggleLocation: () => _toggleLocationSharing(active),
                onEnd: () => _endRide(active),
              )
            else
              const _ScanPrompt(),
          ],
        ),
      ),
    );
  }
}

class _ActiveRideCard extends StatelessWidget {
  const _ActiveRideCard({
    required this.ride,
    required this.sharingLocation,
    required this.onToggleLocation,
    required this.onEnd,
  });

  final ActiveRide ride;
  final bool sharingLocation;
  final VoidCallback onToggleLocation;
  final VoidCallback onEnd;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

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
                  const LiveDot(),
                  const SizedBox(width: 8),
                  Text('سفر در حال انجام', style: theme.textTheme.titleSmall),
                  const Spacer(),
                  StatusBadge(
                    label: ride.status == 'pending_alighting' ? 'در انتظار تأیید' : 'فعال',
                    colorToken: ride.status == 'pending_alighting' ? 'warning' : 'success',
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.lg),

              Text(ride.lineName ?? 'خط نامشخص', style: theme.textTheme.headlineMedium),
              const SizedBox(height: 4),
              Text('مقصد: ${ride.destination ?? '—'}', style: theme.textTheme.bodySmall),

              const SizedBox(height: AppSpacing.xl),

              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: 'ایستگاه بعدی',
                      value: ride.nextStop ?? '—',
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: StatTile(
                      label: 'زمان رسیدن',
                      value: Format.minutes(ride.nextStopEtaSeconds),
                      accent: AppColors.brand300,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.sm),
              Row(
                children: [
                  Expanded(
                    child: StatTile(label: 'کرایه پرداختی', value: ride.formattedFare),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: StatTile(
                      label: 'مسافران داخل اتوبوس',
                      value: Format.number(ride.passengerCount),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),

        const SizedBox(height: AppSpacing.md),

        // Location sharing is off by default and clearly explained: it exists
        // solely so the ride closes itself, not for tracking.
        GlassCard(
          child: Row(
            children: [
              Icon(
                sharingLocation ? Icons.location_on_rounded : Icons.location_off_outlined,
                color: sharingLocation ? AppColors.brand300 : AppColors.ink400,
                size: 20,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text('تشخیص خودکار پیاده شدن', style: theme.textTheme.titleSmall),
                    const SizedBox(height: 2),
                    Text(
                      'موقعیت شما فقط تا پایان همین سفر و برای بستن خودکار آن استفاده می‌شود.',
                      style: theme.textTheme.labelSmall,
                    ),
                  ],
                ),
              ),
              Switch(value: sharingLocation, onChanged: (_) => onToggleLocation()),
            ],
          ),
        ),

        const SizedBox(height: AppSpacing.md),
        OutlinedButton.icon(
          onPressed: onEnd,
          icon: const Icon(Icons.logout_rounded, size: 18),
          label: const Text('پیاده شدم'),
        ),
      ],
    );
  }
}

class _ScanPrompt extends ConsumerWidget {
  const _ScanPrompt();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final wallet = ref.watch(walletProvider);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        GlassCard(
          strong: true,
          padding: const EdgeInsets.all(AppSpacing.xl),
          child: Column(
            children: [
              Container(
                width: 78,
                height: 78,
                decoration: BoxDecoration(
                  color: AppColors.brand500.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(24),
                ),
                child: const Icon(Icons.qr_code_scanner_rounded, size: 38, color: AppColors.brand300),
              ),
              const SizedBox(height: AppSpacing.xl),
              Text('پرداخت کرایه', style: theme.textTheme.titleLarge),
              const SizedBox(height: AppSpacing.sm),
              Text(
                'کد QR داخل اتوبوس را اسکن کنید. کرایه بر اساس نرخ مصوب محاسبه و از کیف پول شما کسر می‌شود.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodySmall,
              ),
              const SizedBox(height: AppSpacing.xl),

              wallet.when(
                loading: () => const ShimmerBox(height: 52),
                error: (_, __) => const SizedBox.shrink(),
                data: (data) => Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  decoration: BoxDecoration(
                    color: AppColors.glassFill,
                    borderRadius: AppRadii.fieldBorder,
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.account_balance_wallet_outlined,
                          size: 18, color: AppColors.ink400),
                      const SizedBox(width: 10),
                      Text('موجودی', style: theme.textTheme.labelMedium),
                      const Spacer(),
                      Text(
                        data.formattedBalance,
                        style: theme.textTheme.titleSmall?.copyWith(color: AppColors.brand300),
                      ),
                    ],
                  ),
                ),
              ),

              const SizedBox(height: AppSpacing.lg),
              FilledButton.icon(
                onPressed: () async {
                  final boarded = await Navigator.of(context).push<bool>(
                    MaterialPageRoute(builder: (_) => const ScanScreen()),
                  );

                  if (boarded == true) {
                    ref
                      ..invalidate(activeRideProvider)
                      ..invalidate(walletProvider)
                      ..invalidate(walletTransactionsProvider);
                  }
                },
                icon: const Icon(Icons.qr_code_scanner_rounded, size: 20),
                label: const Text('اسکن کد اتوبوس'),
              ),
            ],
          ),
        ),

        const SizedBox(height: AppSpacing.lg),
        const SampleDataNotice(),
      ],
    );
  }
}
