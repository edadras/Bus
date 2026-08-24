import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// School service invoices, and paying one from the wallet.
///
/// The same wallet as every fare, through the same ledger: a school fee is not
/// a special kind of money.
class SchoolInvoicesScreen extends ConsumerStatefulWidget {
  const SchoolInvoicesScreen({super.key});

  @override
  ConsumerState<SchoolInvoicesScreen> createState() => _SchoolInvoicesScreenState();
}

class _SchoolInvoicesScreenState extends ConsumerState<SchoolInvoicesScreen> {
  String? _paying;

  Future<void> _pay(SchoolInvoice invoice) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(Format.tr('school_service.pay_title')),
        content: Text(
          Format.tr('school_service.pay_confirm', {'amount': invoice.formattedAmount}),
          textAlign: TextAlign.center,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(Format.tr('common.cancel')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(Format.tr('school_service.pay')),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    setState(() => _paying = invoice.uuid);

    try {
      await ref.read(transitApiProvider).paySchoolInvoice(invoice.uuid);

      ref
        ..invalidate(schoolInvoicesProvider)
        ..invalidate(walletProvider)
        ..invalidate(walletTransactionsProvider);

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(Format.tr('school_service.paid'))),
        );
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _paying = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final invoices = ref.watch(schoolInvoicesProvider);
    final theme = Theme.of(context);

    return AppScaffold(
      title: Format.tr('school_service.invoices'),
      leading: const BackButton(),
      onRefresh: () async {
        ref.invalidate(schoolInvoicesProvider);
        await ref.read(schoolInvoicesProvider.future);
      },
      body: invoices.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.brand400)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('school_service.load_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(schoolInvoicesProvider),
        ),
        data: (items) => items.isEmpty
            ? EmptyState(
                icon: Icons.receipt_long_outlined,
                message: Format.tr('school_service.no_invoices'),
              )
            : ListView(
                padding: const EdgeInsets.only(bottom: 110),
                children: [
                  for (final invoice in items)
                    Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                      child: GlassCard(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Text(
                                        invoice.studentName ?? '—',
                                        style: theme.textTheme.titleSmall,
                                      ),
                                      Text(
                                        Format.digits(
                                          '${invoice.periodStart ?? ''} – ${invoice.periodEnd ?? ''}',
                                        ),
                                        style: theme.textTheme.labelSmall,
                                      ),
                                      if (invoice.dueOn != null)
                                        Text(
                                          Format.tr('school_service.due_on', {
                                            'date': Format.digits(invoice.dueOn!),
                                          }),
                                          style: theme.textTheme.labelSmall?.copyWith(
                                            color:
                                                invoice.isOverdue ? AppColors.danger : null,
                                          ),
                                        ),
                                    ],
                                  ),
                                ),
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Text(
                                      invoice.formattedAmount,
                                      style: theme.textTheme.titleSmall,
                                    ),
                                    StatusBadge(
                                      label: invoice.statusLabel,
                                      colorToken: invoice.statusColor,
                                    ),
                                  ],
                                ),
                              ],
                            ),
                            if (invoice.isPayable) ...[
                              const SizedBox(height: AppSpacing.md),
                              FilledButton(
                                onPressed:
                                    _paying == null ? () => _pay(invoice) : null,
                                child: Text(
                                  _paying == invoice.uuid
                                      ? Format.tr('school_service.paying')
                                      : Format.tr('school_service.pay'),
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                    ),
                ],
              ),
      ),
    );
  }
}
