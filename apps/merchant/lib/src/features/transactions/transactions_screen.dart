import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// Transaction list with refund.
///
/// Refunding is gated on the staff member's own permission and on the
/// transaction not yet being settled — both enforced server-side; the UI only
/// mirrors them so the cashier is not offered an action that will fail.
class TransactionsScreen extends ConsumerWidget {
  const TransactionsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final transactions = ref.watch(merchantTransactionsProvider);
    final merchant = ref.watch(merchantStateProvider).valueOrNull;

    return AppScaffold(
      title: Format.tr('transactions.title'),
      onRefresh: () async {
        ref.invalidate(merchantTransactionsProvider);
        await ref.read(merchantTransactionsProvider.future);
      },
      body: transactions.when(
        loading: () => ListView.separated(
          itemCount: 4,
          separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
          itemBuilder: (_, __) => const ShimmerBox(height: 76),
        ),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('transactions.load_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(merchantTransactionsProvider),
        ),
        data: (items) => items.isEmpty
            ? EmptyState(
                icon: Icons.receipt_long_outlined,
                message: Format.tr('transactions.empty'),
              )
            : ListView.separated(
                padding: const EdgeInsets.only(bottom: 110),
                itemCount: items.length,
                separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
                itemBuilder: (context, index) => _TransactionCard(
                  transaction: items[index],
                  canRefund: merchant?.canRefund ?? false,
                  onRefund: () => _refund(context, ref, items[index]),
                ),
              ),
      ),
    );
  }

  Future<void> _refund(
    BuildContext context,
    WidgetRef ref,
    Map<String, dynamic> transaction,
  ) async {
    final controller = TextEditingController();

    final reason = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(Format.tr('transactions.refund')),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              Format.tr('transactions.refund_body', {
                'amount': transaction['formatted_amount'],
              }),
              style: Theme.of(context).textTheme.bodySmall,
            ),
            const SizedBox(height: AppSpacing.lg),
            TextField(
              controller: controller,
              autofocus: true,
              maxLength: 200,
              decoration: InputDecoration(
                labelText: Format.tr('transactions.refund_reason'),
                counterText: '',
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: Text(Format.tr('common.cancel')),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: Text(Format.tr('transactions.refund')),
          ),
        ],
      ),
    );

    if (reason == null || reason.isEmpty) return;

    try {
      await ref.read(transitApiProvider).refundMerchantTransaction(
            transaction['uuid'] as String,
            reason,
          );

      ref
        ..invalidate(merchantTransactionsProvider)
        ..invalidate(merchantStateProvider);

      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(Format.tr('transactions.refunded'))),
        );
      }
    } on ApiException catch (error) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }
}

class _TransactionCard extends StatelessWidget {
  const _TransactionCard({
    required this.transaction,
    required this.canRefund,
    required this.onRefund,
  });

  final Map<String, dynamic> transaction;
  final bool canRefund;
  final VoidCallback onRefund;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final status = transaction['status'] as String? ?? 'completed';
    final isRefunded = transaction['refunded_at'] != null || status == 'reversed';
    final isSettled = transaction['is_settled'] == true;
    final payer = transaction['payer'] as Map<String, dynamic>?;

    return GlassCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      transaction['formatted_amount'] as String? ?? '—',
                      style: theme.textTheme.titleMedium?.copyWith(
                        color: isRefunded ? AppColors.ink400 : AppColors.ink50,
                        decoration: isRefunded ? TextDecoration.lineThrough : null,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      Format.digits(transaction['reference'] as String? ?? ''),
                      style: theme.textTheme.labelSmall,
                      textDirection: TextDirection.ltr,
                    ),
                  ],
                ),
              ),
              StatusBadge(
                label: transaction['status_label'] as String? ?? '',
                colorToken: isRefunded ? 'neutral' : 'success',
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.md),
          Row(
            children: [
              const Icon(Icons.person_outline_rounded, size: 14, color: AppColors.ink500),
              const SizedBox(width: 5),
              // The payer is masked by the API: a cashier needs to recognise a
              // payment, not collect customers' phone numbers.
              Text(
                Format.digits(payer?['mobile'] as String? ?? '—'),
                style: theme.textTheme.labelSmall,
              ),
              const Spacer(),
              Text(
                Format.relative(
                  transaction['created_at'] == null
                      ? null
                      : DateTime.tryParse(transaction['created_at'] as String),
                ),
                style: theme.textTheme.labelSmall,
              ),
            ],
          ),
          if (transaction['description'] != null) ...[
            const SizedBox(height: 6),
            Text(transaction['description'] as String, style: theme.textTheme.bodySmall),
          ],
          if (canRefund && !isRefunded && !isSettled) ...[
            const Divider(height: AppSpacing.xl),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton.icon(
                onPressed: onRefund,
                style: TextButton.styleFrom(foregroundColor: AppColors.danger),
                icon: const Icon(Icons.undo_rounded, size: 16),
                label: Text(Format.tr('transactions.refund')),
              ),
            ),
          ] else if (isSettled && !isRefunded) ...[
            const SizedBox(height: 8),
            Text(
              Format.tr('transactions.settled_note'),
              style: theme.textTheme.labelSmall?.copyWith(color: AppColors.ink500),
            ),
          ],
        ],
      ),
    );
  }
}
