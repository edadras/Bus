import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// Profile, ride history and the app's privacy disclosure.
class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);
    final history = ref.watch(rideHistoryProvider);
    final theme = Theme.of(context);
    final user = auth.user;

    return AppScaffold(
      title: 'حساب کاربری',
      onRefresh: () async {
        ref.invalidate(rideHistoryProvider);
        await ref.read(rideHistoryProvider.future);
      },
      body: ListView(
        padding: const EdgeInsets.only(bottom: 110),
        children: [
          if (user == null)
            GlassCard(
              child: Column(
                children: [
                  const Icon(Icons.person_outline_rounded, size: 34, color: AppColors.ink400),
                  const SizedBox(height: AppSpacing.md),
                  Text('وارد حساب کاربری نشده‌اید.', style: theme.textTheme.bodyMedium),
                  const SizedBox(height: AppSpacing.lg),
                  FilledButton(
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => const OtpLoginView(
                          client: 'passenger',
                          title: 'ورود به همسفر',
                          subtitle: 'با شماره موبایل خود وارد شوید',
                        ),
                      ),
                    ),
                    child: const Text('ورود با شماره موبایل'),
                  ),
                ],
              ),
            )
          else ...[
            GlassCard(
              strong: true,
              child: Row(
                children: [
                  Container(
                    width: 54,
                    height: 54,
                    decoration: BoxDecoration(
                      gradient: const LinearGradient(
                        colors: [AppColors.brand400, AppColors.brand600],
                      ),
                      borderRadius: BorderRadius.circular(18),
                    ),
                    alignment: Alignment.center,
                    child: Text(
                      user.name.isNotEmpty ? user.name.characters.first : '؟',
                      style: const TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w700,
                        color: Colors.white,
                      ),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.lg),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(user.name, style: theme.textTheme.titleMedium),
                        const SizedBox(height: 3),
                        Text(Format.digits(user.mobile), style: theme.textTheme.bodySmall),
                        if (user.cityName != null) ...[
                          const SizedBox(height: 3),
                          Text(user.cityName!, style: theme.textTheme.labelSmall),
                        ],
                      ],
                    ),
                  ),
                ],
              ),
            ),

            const SizedBox(height: AppSpacing.lg),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: Text('سفرهای اخیر', style: theme.textTheme.titleSmall),
            ),
            const SizedBox(height: AppSpacing.sm),

            history.when(
              loading: () => const Column(
                children: [ShimmerBox(height: 66), SizedBox(height: 8), ShimmerBox(height: 66)],
              ),
              error: (_, __) => const EmptyState(message: 'دریافت تاریخچه ممکن نشد.'),
              data: (rides) => rides.isEmpty
                  ? const EmptyState(
                      icon: Icons.directions_bus_outlined,
                      message: 'هنوز سفری ثبت نشده است.',
                    )
                  : Column(
                      children: [
                        for (final ride in rides.take(20)) ...[
                          _RideTile(ride: ride),
                          const SizedBox(height: AppSpacing.sm),
                        ],
                      ],
                    ),
            ),
          ],

          const SizedBox(height: AppSpacing.lg),

          // Privacy is stated plainly rather than buried: the app asks for
          // location, so it owes the user a clear account of what it does.
          GlassCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(Icons.shield_outlined, size: 18, color: AppColors.ink400),
                    const SizedBox(width: 8),
                    Text('حریم خصوصی', style: theme.textTheme.titleSmall),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                Text(
                  'موقعیت مکانی شما فقط هنگام یک سفر فعال و تنها برای تشخیص پیاده شدن '
                  'استفاده می‌شود. این داده حداکثر تا ۲۴ ساعت نگهداری و سپس حذف می‌شود '
                  'و در اختیار سایر کاربران قرار نمی‌گیرد.',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),

          const SizedBox(height: AppSpacing.md),
          const SampleDataNotice(),

          if (user != null) ...[
            const SizedBox(height: AppSpacing.lg),
            OutlinedButton.icon(
              onPressed: () async {
                final confirmed = await showDialog<bool>(
                  context: context,
                  builder: (context) => AlertDialog(
                    title: const Text('خروج از حساب'),
                    content: const Text('برای پرداخت کرایه باید دوباره وارد شوید. ادامه می‌دهید؟'),
                    actions: [
                      TextButton(
                        onPressed: () => Navigator.pop(context, false),
                        child: const Text('انصراف'),
                      ),
                      FilledButton(
                        onPressed: () => Navigator.pop(context, true),
                        child: const Text('خروج'),
                      ),
                    ],
                  ),
                );

                if (confirmed == true) {
                  await ref.read(authControllerProvider.notifier).signOut();
                }
              },
              icon: const Icon(Icons.logout_rounded, size: 18),
              label: const Text('خروج از حساب'),
            ),
          ],
        ],
      ),
    );
  }
}

class _RideTile extends StatelessWidget {
  const _RideTile({required this.ride});

  final RideHistoryItem ride;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: AppColors.glassFill,
              borderRadius: BorderRadius.circular(12),
            ),
            alignment: Alignment.center,
            child: Text(
              Format.digits(ride.lineCode ?? '—'),
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  ride.lineName ?? 'سفر',
                  style: theme.textTheme.titleSmall,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 2),
                Text(
                  [
                    Format.relative(ride.boardedAt),
                    if (ride.durationSeconds != null) Format.duration(ride.durationSeconds),
                  ].join(' · '),
                  style: theme.textTheme.labelSmall,
                ),
              ],
            ),
          ),
          Text(ride.formattedFare, style: theme.textTheme.titleSmall),
        ],
      ),
    );
  }
}
