import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../providers.dart';

/// Wallet: balance, top-up and statement.
///
/// Top-up hands off to the gateway in an external browser and then polls the
/// payment's status, because the wallet is only credited once the server has
/// verified the callback — the app must never assume success from a redirect.
class WalletScreen extends ConsumerStatefulWidget {
  const WalletScreen({super.key});

  @override
  ConsumerState<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends ConsumerState<WalletScreen> {
  bool _busy = false;

  static const _quickAmounts = [200000, 500000, 1000000, 2000000];

  Future<void> _topup(int amount) async {
    setState(() => _busy = true);

    try {
      final result = await ref.read(transitApiProvider).topup(amount);

      final launched = await launchUrl(
        Uri.parse(result.redirectUrl),
        mode: LaunchMode.externalApplication,
      );

      if (!launched && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(Format.tr('wallet.gateway_failed'))),
        );

        return;
      }

      if (mounted) await _awaitPayment(result.paymentUuid);
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Waits for the server to confirm the payment. The dialog is dismissible:
  /// a user who abandons the gateway must not be trapped here.
  Future<void> _awaitPayment(String paymentUuid) async {
    await showDialog<void>(
      context: context,
      builder: (context) => _PaymentWaitDialog(paymentUuid: paymentUuid),
    );

    ref
      ..invalidate(walletProvider)
      ..invalidate(walletTransactionsProvider);
  }

  Future<void> _customAmount() async {
    final controller = TextEditingController();

    final amount = await showDialog<int>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(Format.tr('wallet.topup_amount')),
        content: TextField(
          controller: controller,
          keyboardType: TextInputType.number,
          autofocus: true,
          textAlign: TextAlign.center,
          decoration: InputDecoration(suffixText: Format.tr('unit.toman')),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context), child: Text(Format.tr('common.cancel'))),
          FilledButton(
            onPressed: () {
              final toman = int.tryParse(Format.toLatinDigits(controller.text));

              // The API speaks in rial minor units; the user types Toman.
              Navigator.pop(context, toman == null ? null : toman * 10);
            },
            child: Text(Format.tr('wallet.continue')),
          ),
        ],
      ),
    );

    if (amount != null && amount > 0) await _topup(amount);
  }

  @override
  Widget build(BuildContext context) {
    final wallet = ref.watch(walletProvider);
    final transactions = ref.watch(walletTransactionsProvider);
    final theme = Theme.of(context);

    return AppScaffold(
      title: Format.tr('wallet.title'),
      onRefresh: () async {
        ref
          ..invalidate(walletProvider)
          ..invalidate(walletTransactionsProvider);
        await ref.read(walletProvider.future);
      },
      body: ListView(
        padding: const EdgeInsets.only(bottom: 110),
        children: [
          wallet.when(
            loading: () => const ShimmerBox(height: 190),
            error: (error, _) => ErrorState(
              message: error is ApiException ? error.message : Format.tr('wallet.load_failed'),
              isOffline: error is NetworkException,
              onRetry: () => ref.invalidate(walletProvider),
            ),
            data: (data) => GlassCard(
              strong: true,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Text(Format.tr('wallet.balance'), style: theme.textTheme.labelMedium),
                      const Spacer(),
                      StatusBadge(
                        label: data.statusLabel,
                        colorToken: data.canSpend ? 'success' : 'danger',
                      ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.md),
                  Text(data.formattedBalance, style: theme.textTheme.displayMedium),
                  const SizedBox(height: AppSpacing.xl),
                  Text(Format.tr('wallet.quick_topup'), style: theme.textTheme.labelMedium),
                  const SizedBox(height: AppSpacing.sm),
                  Wrap(
                    spacing: AppSpacing.sm,
                    runSpacing: AppSpacing.sm,
                    children: [
                      for (final amount in _quickAmounts)
                        OutlinedButton(
                          onPressed: _busy ? null : () => _topup(amount),
                          style: OutlinedButton.styleFrom(
                            minimumSize: const Size(96, 42),
                            padding: const EdgeInsets.symmetric(horizontal: 14),
                          ),
                          child: Text(Format.money(amount, withSuffix: false)),
                        ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.md),
                  FilledButton.icon(
                    onPressed: _busy ? null : _customAmount,
                    icon: _busy
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                          )
                        : const Icon(Icons.add_rounded, size: 20),
                    label: Text(Format.tr('wallet.topup')),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: Text(Format.tr('wallet.recent_transactions'), style: theme.textTheme.titleSmall),
          ),
          const SizedBox(height: AppSpacing.sm),
          transactions.when(
            loading: () => const Column(
              children: [
                ShimmerBox(height: 62),
                SizedBox(height: AppSpacing.sm),
                ShimmerBox(height: 62),
              ],
            ),
            error: (_, __) => EmptyState(message: Format.tr('wallet.transactions_failed')),
            data: (entries) => entries.isEmpty
                ? EmptyState(
                    icon: Icons.receipt_long_outlined,
                    message: Format.tr('wallet.no_transactions'),
                  )
                : Column(
                    children: [
                      for (final entry in entries) ...[
                        _TransactionTile(entry: entry),
                        const SizedBox(height: AppSpacing.sm),
                      ],
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}

class _TransactionTile extends StatelessWidget {
  const _TransactionTile({required this.entry});

  final LedgerEntry entry;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final color = entry.isCredit ? AppColors.brand300 : AppColors.ink200;

    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color:
                  entry.isCredit ? AppColors.brand500.withValues(alpha: 0.15) : AppColors.glassFill,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(
              entry.isCredit ? Icons.south_west_rounded : Icons.north_east_rounded,
              size: 18,
              color: color,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(entry.title, style: theme.textTheme.titleSmall, maxLines: 1),
                const SizedBox(height: 2),
                Text(Format.relative(entry.createdAt), style: theme.textTheme.labelSmall),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                '${entry.isCredit ? '+' : '−'} ${entry.formattedAmount}',
                style: theme.textTheme.titleSmall?.copyWith(color: color),
              ),
              const SizedBox(height: 2),
              Text(
                Format.tr('wallet.balance_after', {'amount': Format.money(entry.balanceAfter)}),
                style: theme.textTheme.labelSmall?.copyWith(fontSize: 10),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Polls the payment until the server reports a final state.
class _PaymentWaitDialog extends ConsumerStatefulWidget {
  const _PaymentWaitDialog({required this.paymentUuid});

  final String paymentUuid;

  @override
  ConsumerState<_PaymentWaitDialog> createState() => _PaymentWaitDialogState();
}

class _PaymentWaitDialogState extends ConsumerState<_PaymentWaitDialog> {
  String _status = 'pending';
  String? _message;
  int _attempts = 0;

  @override
  void initState() {
    super.initState();
    unawaited(_poll());
  }

  Future<void> _poll() async {
    // Roughly two minutes of polling, then stop and let the user check later:
    // an unbounded poll would sit there draining battery.
    while (mounted && _attempts < 40 && (_status == 'pending' || _status == 'initiated')) {
      await Future<void>.delayed(const Duration(seconds: 3));

      if (!mounted) return;

      try {
        final result = await ref.read(transitApiProvider).paymentStatus(widget.paymentUuid);

        if (!mounted) return;

        setState(() {
          _status = result['status'] as String? ?? 'pending';
          _message = result['failure_reason'] as String?;
          _attempts++;
        });
      } catch (_) {
        if (mounted) setState(() => _attempts++);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final succeeded = _status == 'succeeded';
    final failed = _status == 'failed' || _status == 'cancelled';

    return AlertDialog(
      icon: Icon(
        succeeded
            ? Icons.check_circle_rounded
            : failed
                ? Icons.cancel_rounded
                : Icons.hourglass_top_rounded,
        size: 42,
        color: succeeded
            ? AppColors.brand400
            : failed
                ? AppColors.danger
                : AppColors.ink300,
      ),
      title: Text(
        succeeded
            ? Format.tr('wallet.topup_succeeded')
            : failed
                ? Format.tr('wallet.topup_failed')
                : Format.tr('wallet.topup_pending'),
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (!succeeded && !failed) ...[
            const LinearProgressIndicator(),
            const SizedBox(height: AppSpacing.md),
          ],
          Text(
            succeeded
                ? Format.tr('wallet.topup_succeeded_body')
                : failed
                    ? (_message ?? Format.tr('wallet.topup_failed_body'))
                    : Format.tr('wallet.topup_pending_body'),
            textAlign: TextAlign.center,
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: Text(
            succeeded || failed ? Format.tr('common.close') : Format.tr('wallet.check_later'),
          ),
        ),
      ],
    );
  }
}
