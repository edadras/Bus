import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// The in-app inbox.
///
/// This is the durable record of everything the platform has told the user:
/// arrival alerts, low balance warnings, replies to a complaint. Push delivery
/// is best-effort — a phone can be off, out of coverage, or have notifications
/// switched off entirely — so nothing is ever *only* a push.
class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final notifications = ref.watch(notificationsProvider);
    final controller = ref.read(notificationsProvider.notifier);
    final theme = Theme.of(context);

    return AppScaffold(
      title: Format.tr('notifications.title'),
      actions: [
        TextButton(
          onPressed: () => controller.markAllRead(),
          child: Text(Format.tr('notifications.mark_all_read')),
        ),
      ],
      onRefresh: controller.refresh,
      body: notifications.when(
        loading: () => ListView(
          padding: const EdgeInsets.only(top: AppSpacing.md, bottom: 110),
          children: const [
            ShimmerBox(height: 78),
            SizedBox(height: AppSpacing.sm),
            ShimmerBox(height: 78),
            SizedBox(height: AppSpacing.sm),
            ShimmerBox(height: 78),
          ],
        ),
        error: (_, __) => ListView(
          children: [
            SizedBox(height: 80),
            EmptyState(message: Format.tr('notifications.failed')),
          ],
        ),
        data: (items) => items.isEmpty
            ? ListView(
                children: [
                  SizedBox(height: 80),
                  EmptyState(
                    icon: Icons.notifications_none_rounded,
                    message: Format.tr('notifications.empty'),
                  ),
                ],
              )
            : ListView.separated(
                padding: const EdgeInsets.only(top: AppSpacing.md, bottom: 110),
                itemCount: items.length,
                separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
                itemBuilder: (context, index) {
                  final item = items[index];

                  return GlassCard(
                    strong: !item.read,
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
                    onTap: () => controller.markRead(item),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Container(
                          width: 38,
                          height: 38,
                          decoration: BoxDecoration(
                            color: AppColors.glassFill,
                            borderRadius: BorderRadius.circular(12),
                          ),
                          alignment: Alignment.center,
                          child: Icon(_iconFor(item.type), size: 18, color: AppColors.brand400),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                item.title ?? Format.tr('notifications.fallback_title'),
                                style: theme.textTheme.titleSmall?.copyWith(
                                  fontWeight: item.read ? FontWeight.w500 : FontWeight.w700,
                                ),
                              ),
                              if (item.body != null) ...[
                                const SizedBox(height: 4),
                                Text(item.body!, style: theme.textTheme.bodySmall),
                              ],
                              const SizedBox(height: 6),
                              Text(
                                Format.relative(item.createdAt),
                                style: theme.textTheme.labelSmall,
                              ),
                            ],
                          ),
                        ),
                        // The unread dot is the only affordance here: tapping
                        // anywhere on the card marks it read.
                        if (!item.read)
                          Container(
                            margin: const EdgeInsets.only(top: 6),
                            width: 8,
                            height: 8,
                            decoration: const BoxDecoration(
                              color: AppColors.brand400,
                              shape: BoxShape.circle,
                            ),
                          ),
                      ],
                    ),
                  );
                },
              ),
      ),
    );
  }

  static IconData _iconFor(String type) => switch (type) {
        'bus_approaching' => Icons.directions_bus_rounded,
        'low_balance' => Icons.account_balance_wallet_rounded,
        'complaint_reply' => Icons.support_agent_rounded,
        _ => Icons.notifications_rounded,
      };
}
