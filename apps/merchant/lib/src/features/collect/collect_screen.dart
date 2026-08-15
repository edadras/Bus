import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

import '../../providers.dart';

/// The till screen: show a rotating payment code for the customer to scan.
///
/// The code is deliberately short-lived. A photograph of the till's QR must be
/// worthless a few seconds later, which is why the token carries a time step
/// and a single-use nonce rather than being a static merchant identifier.
class CollectScreen extends ConsumerStatefulWidget {
  const CollectScreen({super.key});

  @override
  ConsumerState<CollectScreen> createState() => _CollectScreenState();
}

class _CollectScreenState extends ConsumerState<CollectScreen> {
  int? _terminalId;

  @override
  void initState() {
    super.initState();

    // The till sits on a counter with the screen facing the customer; letting
    // it sleep mid-transaction is the main practical failure of these screens.
    unawaited(WakelockPlus.enable());
  }

  @override
  void dispose() {
    unawaited(WakelockPlus.disable());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(merchantStateProvider);
    final theme = Theme.of(context);

    return AppScaffold(
      title: Format.tr('collect.title'),
      actions: [
        IconButton(
          onPressed: () => ref.read(authControllerProvider.notifier).signOut(),
          icon: const Icon(Icons.logout_rounded, size: 20),
        ),
      ],
      onRefresh: () async {
        ref.invalidate(merchantStateProvider);
        await ref.read(merchantStateProvider.future);
      },
      body: state.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.warning)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('collect.load_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(merchantStateProvider),
        ),
        data: (merchant) {
          if (!merchant.isActive) {
            return EmptyState(
              icon: Icons.pending_outlined,
              message: Format.tr('collect.not_active'),
            );
          }

          if (merchant.terminals.isEmpty) {
            return EmptyState(
              icon: Icons.point_of_sale_outlined,
              message: Format.tr('collect.no_terminal'),
            );
          }

          final terminalId = _terminalId ?? merchant.terminals.first.id;

          return ListView(
            padding: const EdgeInsets.only(bottom: 110),
            children: [
              GlassCard(
                strong: true,
                child: Row(
                  children: [
                    Container(
                      width: 46,
                      height: 46,
                      decoration: BoxDecoration(
                        color: AppColors.warning.withValues(alpha: 0.15),
                        borderRadius: BorderRadius.circular(15),
                      ),
                      child: const Icon(Icons.storefront_rounded, color: AppColors.warning),
                    ),
                    const SizedBox(width: AppSpacing.md),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(merchant.name, style: theme.textTheme.titleMedium),
                          const SizedBox(height: 2),
                          Text(merchant.typeLabel, style: theme.textTheme.labelSmall),
                        ],
                      ),
                    ),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(Format.tr('collect.balance'), style: theme.textTheme.labelSmall),
                        Text(
                          merchant.formattedBalance,
                          style: theme.textTheme.titleSmall?.copyWith(color: AppColors.brand300),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              if (merchant.terminals.length > 1) ...[
                const SizedBox(height: AppSpacing.md),
                SizedBox(
                  height: 40,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    itemCount: merchant.terminals.length,
                    separatorBuilder: (_, __) => const SizedBox(width: AppSpacing.sm),
                    itemBuilder: (context, index) {
                      final terminal = merchant.terminals[index];
                      final selected = terminal.id == terminalId;

                      return ChoiceChip(
                        selected: selected,
                        label: Text(terminal.name),
                        selectedColor: AppColors.warning.withValues(alpha: 0.2),
                        onSelected: (_) => setState(() => _terminalId = terminal.id),
                      );
                    },
                  ),
                ),
              ],
              const SizedBox(height: AppSpacing.lg),
              _TerminalQr(terminalId: terminalId),
              const SizedBox(height: AppSpacing.lg),
              GlassCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(Format.tr('collect.pending_settlement'),
                        style: theme.textTheme.titleSmall),
                    const SizedBox(height: AppSpacing.md),
                    Row(
                      children: [
                        Expanded(
                          child: StatTile(
                            label: Format.tr('collect.transactions'),
                            value: Format.number(merchant.pendingSettlement.transactionCount),
                          ),
                        ),
                        const SizedBox(width: AppSpacing.sm),
                        Expanded(
                          child: StatTile(
                            label: Format.tr('collect.settleable'),
                            value: Format.money(merchant.pendingSettlement.netAmount),
                            accent: AppColors.brand300,
                          ),
                        ),
                      ],
                    ),
                    if (merchant.isManager) ...[
                      const SizedBox(height: AppSpacing.md),
                      OutlinedButton.icon(
                        onPressed: merchant.pendingSettlement.transactionCount == 0
                            ? null
                            : _requestSettlement,
                        icon: const Icon(Icons.account_balance_outlined, size: 18),
                        label: Text(Format.tr('collect.request_settlement')),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          );
        },
      ),
    );
  }

  Future<void> _requestSettlement() async {
    try {
      final result = await ref.read(transitApiProvider).requestSettlement();

      if (!mounted) return;

      ref.invalidate(merchantStateProvider);

      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          icon: const Icon(Icons.check_circle_rounded, color: AppColors.brand400, size: 40),
          title: Text(Format.tr('collect.settlement_requested')),
          content: Text(
            Format.tr('collect.settlement_detail', {
              'reference': Format.digits('${result['reference']}'),
              'amount': result['formatted_net'],
            }),
            textAlign: TextAlign.center,
          ),
          actions: [
            FilledButton(
              onPressed: () => Navigator.pop(context),
              child: Text(Format.tr('collect.ok')),
            ),
          ],
        ),
      );
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }
}

class _TerminalQr extends ConsumerWidget {
  const _TerminalQr({required this.terminalId});

  final int terminalId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final token = ref.watch(terminalTokenProvider(terminalId));
    final theme = Theme.of(context);

    return GlassCard(
      strong: true,
      padding: const EdgeInsets.all(AppSpacing.xl),
      child: Column(
        children: [
          Text(Format.tr('collect.payment_code'), style: theme.textTheme.titleSmall),
          const SizedBox(height: AppSpacing.sm),
          Text(
            Format.tr('collect.payment_code_body'),
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: AppSpacing.xl),
          token.when(
            loading: () => const SizedBox(
              height: 240,
              child: Center(child: CircularProgressIndicator(color: AppColors.warning)),
            ),
            error: (error, _) => SizedBox(
              height: 240,
              child: ErrorState(
                message: error is ApiException ? error.message : Format.tr('collect.code_failed'),
                isOffline: error is NetworkException,
                onRetry: () => ref.read(terminalTokenProvider(terminalId).notifier).refresh(),
              ),
            ),
            data: (data) => Column(
              children: [
                // White quiet zone: scanners struggle badly with a dark or
                // tinted QR background.
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: QrImageView(
                    data: data.token,
                    version: QrVersions.auto,
                    size: 220,
                    gapless: true,
                    errorCorrectionLevel: QrErrorCorrectLevel.M,
                  ),
                ),
                const SizedBox(height: AppSpacing.lg),
                _Countdown(seconds: data.expiresIn, total: data.rotationSeconds),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  Format.tr('collect.terminal_id', {'id': data.publicId}),
                  style: theme.textTheme.labelSmall,
                  textDirection: TextDirection.ltr,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Visual countdown to the next code, so the cashier can tell a customer to
/// wait a moment rather than wondering why a scan failed.
class _Countdown extends StatefulWidget {
  const _Countdown({required this.seconds, required this.total});

  final int seconds;
  final int total;

  @override
  State<_Countdown> createState() => _CountdownState();
}

class _CountdownState extends State<_Countdown> {
  late int _remaining = widget.seconds;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _start();
  }

  @override
  void didUpdateWidget(_Countdown oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.seconds != widget.seconds) {
      setState(() => _remaining = widget.seconds);
      _start();
    }
  }

  void _start() {
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;

      setState(() => _remaining = (_remaining - 1).clamp(0, widget.total));
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final progress = widget.total == 0 ? 0.0 : _remaining / widget.total;

    return Column(
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(999),
          child: LinearProgressIndicator(
            value: progress,
            minHeight: 6,
            backgroundColor: AppColors.ink700,
            color: progress < 0.25 ? AppColors.warning : AppColors.brand400,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          Format.tr('collect.code_validity', {'seconds': Format.number(_remaining)}),
          style: Theme.of(context).textTheme.labelSmall,
        ),
      ],
    );
  }
}
