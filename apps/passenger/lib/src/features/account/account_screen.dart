import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';
import '../notifications/notifications_screen.dart';
import '../school/school_screen.dart';

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
      title: Format.tr('account.title'),
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
                  Text(Format.tr('account.signed_out'), style: theme.textTheme.bodyMedium),
                  const SizedBox(height: AppSpacing.lg),
                  FilledButton(
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => OtpLoginView(
                          client: 'passenger',
                          title: Format.tr('app.sign_in_title'),
                          subtitle: Format.tr('app.sign_in_subtitle'),
                        ),
                      ),
                    ),
                    child: Text(Format.tr('app.sign_in_with_mobile')),
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
                      user.name.isNotEmpty
                          ? user.name.characters.first
                          : Format.tr('account.unknown_initial'),
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
            const _NotificationsTile(),
            const SizedBox(height: AppSpacing.sm),
            const _SchoolServiceTile(),
            const SizedBox(height: AppSpacing.sm),
            const _LanguageTile(),
            const SizedBox(height: AppSpacing.lg),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: Text(Format.tr('account.recent_rides'), style: theme.textTheme.titleSmall),
            ),
            const SizedBox(height: AppSpacing.sm),
            history.when(
              loading: () => const Column(
                children: [ShimmerBox(height: 66), SizedBox(height: 8), ShimmerBox(height: 66)],
              ),
              error: (_, __) => EmptyState(message: Format.tr('account.history_failed')),
              data: (rides) => rides.isEmpty
                  ? EmptyState(
                      icon: Icons.directions_bus_outlined,
                      message: Format.tr('account.no_rides'),
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
                    Text(Format.tr('account.privacy'), style: theme.textTheme.titleSmall),
                  ],
                ),
                const SizedBox(height: AppSpacing.md),
                Text(
                  Format.tr('account.privacy_body'),
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
                    title: Text(Format.tr('account.sign_out')),
                    content: Text(Format.tr('account.sign_out_confirm')),
                    actions: [
                      TextButton(
                        onPressed: () => Navigator.pop(context, false),
                        child: Text(Format.tr('common.cancel')),
                      ),
                      FilledButton(
                        onPressed: () => Navigator.pop(context, true),
                        child: Text(Format.tr('account.sign_out_action')),
                      ),
                    ],
                  ),
                );

                if (confirmed == true) {
                  await ref.read(authControllerProvider.notifier).signOut();
                }
              },
              icon: const Icon(Icons.logout_rounded, size: 18),
              label: Text(Format.tr('account.sign_out')),
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
                  ride.lineName ?? Format.tr('account.ride'),
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

/// Entry point to the inbox, carrying the unread badge.
class _NotificationsTile extends ConsumerWidget {
  const _NotificationsTile();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final notifications = ref.watch(notificationsProvider);
    final unread = notifications.maybeWhen(
      data: (items) => items.where((item) => !item.read).length,
      orElse: () => 0,
    );

    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => const NotificationsScreen()),
      ),
      child: Row(
        children: [
          const Icon(Icons.notifications_none_rounded, size: 20, color: AppColors.ink400),
          const SizedBox(width: 12),
          Expanded(
              child: Text(Format.tr('notifications.title'), style: theme.textTheme.titleSmall)),
          if (unread > 0)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
              decoration: BoxDecoration(
                color: AppColors.brand500,
                borderRadius: BorderRadius.circular(999),
              ),
              child: Text(
                Format.number(unread),
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: Colors.white,
                ),
              ),
            ),
          const SizedBox(width: 6),
          const Icon(Icons.chevron_left_rounded, size: 20, color: AppColors.ink400),
        ],
      ),
    );
  }
}

/// The way into the school service.
///
/// It lives here rather than in a tab of its own because most passengers are
/// not parents; the badge is what makes it findable for the ones who are, and
/// it counts the things that need doing — a company's answer to read, or an
/// invoice to pay.
class _SchoolServiceTile extends ConsumerWidget {
  const _SchoolServiceTile();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final contracts = ref.watch(schoolContractsProvider).valueOrNull;
    final invoices = ref.watch(schoolInvoicesProvider).valueOrNull;

    var pending = 0;

    for (final contract in contracts ?? const <SchoolContract>[]) {
      if (contract.status == 'approved') pending++;
    }

    for (final invoice in invoices ?? const <SchoolInvoice>[]) {
      if (invoice.isPayable) pending++;
    }

    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => const SchoolScreen()),
      ),
      child: Row(
        children: [
          const Icon(Icons.airport_shuttle_outlined, size: 20, color: AppColors.ink400),
          const SizedBox(width: 12),
          Expanded(
            child: Text(Format.tr('school_service.title'), style: theme.textTheme.titleSmall),
          ),
          if (pending > 0)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
              decoration: BoxDecoration(
                color: AppColors.warning,
                borderRadius: BorderRadius.circular(999),
              ),
              child: Text(
                Format.number(pending),
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: AppColors.ink950,
                ),
              ),
            ),
          const SizedBox(width: 6),
          const Icon(Icons.chevron_left_rounded, size: 20, color: AppColors.ink400),
        ],
      ),
    );
  }
}

/// Language choice.
///
/// Persian is the default and stays the default; this is here because a
/// declared second locale nobody can reach is not a supported locale. The
/// choice is stored, so it survives a restart.
class _LanguageTile extends ConsumerWidget {
  const _LanguageTile();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final locale = ref.watch(localeProvider);

    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      child: Row(
        children: [
          const Icon(Icons.translate_rounded, size: 20, color: AppColors.ink400),
          const SizedBox(width: 12),
          Expanded(child: Text(Format.tr('account.language'), style: theme.textTheme.titleSmall)),
          SegmentedButton<String>(
            style: const ButtonStyle(visualDensity: VisualDensity.compact),
            segments: [
              ButtonSegment(value: 'fa', label: Text(Format.tr('account.language_fa'))),
              ButtonSegment(value: 'en', label: Text(Format.tr('account.language_en'))),
            ],
            selected: {locale},
            showSelectedIcon: false,
            onSelectionChanged: (selection) =>
                ref.read(localeProvider.notifier).set(selection.first),
          ),
        ],
      ),
    );
  }
}
