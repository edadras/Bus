import 'package:flutter/material.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// Detail sheet for a bus tapped on the map.
void showTripSheet(BuildContext context, LiveBus bus) {
  showModalBottomSheet<void>(
    context: context,
    backgroundColor: Colors.transparent,
    builder: (context) => _TripSheet(bus: bus),
  );
}

class _TripSheet extends StatelessWidget {
  const _TripSheet({required this.bus});

  final LiveBus bus;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

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
                        'اتوبوس ${Format.digits(bus.busNumber ?? '—')}',
                        style: theme.textTheme.titleLarge,
                      ),
                      const SizedBox(height: 3),
                      Text(
                        'خط ${Format.digits(bus.lineCode ?? '—')} — ${bus.lineName ?? ''}',
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                if (bus.isOffRoute)
                  const StatusBadge(label: 'خارج از مسیر', colorToken: 'warning')
                else if (bus.isIdle)
                  const StatusBadge(label: 'متوقف', colorToken: 'warning')
                else if (bus.isStale)
                  const StatusBadge(label: 'اطلاعات قدیمی', colorToken: 'neutral')
                else
                  const StatusBadge(label: 'در حال حرکت', colorToken: 'success'),
              ],
            ),
            const SizedBox(height: AppSpacing.xl),
            Row(
              children: [
                Expanded(
                  child: StatTile(
                    label: 'ایستگاه بعدی',
                    value: bus.nextStop ?? '—',
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: StatTile(
                    label: 'زمان رسیدن',
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
                    label: 'مسافران',
                    value: Format.number(bus.passengerCount),
                    caption: bus.occupancy > 0
                        ? '${Format.number((bus.occupancy * 100).round())}٪ ظرفیت'
                        : null,
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: StatTile(
                    label: 'سرعت',
                    value: bus.speed == null
                        ? '—'
                        : '${Format.number(bus.speed!.round())} کیلومتر/ساعت',
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
                      child: Text('مقصد: ${bus.destination}', style: theme.textTheme.bodyMedium),
                    ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: AppSpacing.lg),
            Text(
              'آخرین به‌روزرسانی موقعیت: ${Format.relative(bus.updatedAt)}',
              textAlign: TextAlign.center,
              style: theme.textTheme.labelSmall,
            ),
          ],
        ),
      ),
    );
  }
}
