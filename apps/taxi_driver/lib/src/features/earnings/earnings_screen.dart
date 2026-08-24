import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// What the driver has earned, and asking for it.
///
/// Gross, commission and net are shown separately because a driver comparing
/// their own count against a payout will otherwise find the two disagree.
/// Unpaid metered rides sit apart from all three: a fare the passenger never
/// paid is a debt somebody may still settle, not money already taken.
class TaxiEarningsScreen extends ConsumerStatefulWidget {
  const TaxiEarningsScreen({super.key});

  @override
  ConsumerState<TaxiEarningsScreen> createState() => _TaxiEarningsScreenState();
}

class _TaxiEarningsScreenState extends ConsumerState<TaxiEarningsScreen> {
  bool _busy = false;

  Future<void> _requestSettlement() async {
    setState(() => _busy = true);

    try {
      final settlement = await ref.read(transitApiProvider).requestTaxiSettlement();

      ref.invalidate(taxiSettlementsProvider);
      ref.invalidate(taxiStateProvider);

      if (!mounted) return;

      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          icon: const Icon(Icons.check_circle_rounded, color: AppColors.brand400, size: 40),
          title: Text(Format.tr('taxi.settlement_requested')),
          content: Text(
            Format.tr('taxi.settlement_detail', {
              'reference': Format.digits(settlement.reference),
              'amount': settlement.formattedNet,
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
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final earnings = ref.watch(taxiEarningsProvider);
    final settlements = ref.watch(taxiSettlementsProvider);
    final theme = Theme.of(context);

    return AppScaffold(
      title: Format.tr('taxi.earnings_title'),
      onRefresh: () async {
        ref.invalidate(taxiEarningsProvider);
        ref.invalidate(taxiSettlementsProvider);
        await ref.read(taxiEarningsProvider.future);
      },
      body: earnings.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.warning)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('taxi.earnings_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(taxiEarningsProvider),
        ),
        data: (report) => ListView(
          padding: const EdgeInsets.only(bottom: 110),
          children: [
            GlassCard(
              strong: true,
              child: Column(
                children: [
                  Text(Format.tr('taxi.net_30_days'), style: theme.textTheme.labelMedium),
                  const SizedBox(height: 4),
                  Text(
                    Format.money(report.net),
                    style: theme.textTheme.displaySmall?.copyWith(color: AppColors.brand300),
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  Row(
                    children: [
                      Expanded(
                        child: StatTile(
                          label: Format.tr('taxi.gross'),
                          value: Format.money(report.gross),
                        ),
                      ),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(
                        child: StatTile(
                          label: Format.tr('taxi.commission'),
                          value: Format.money(report.commission),
                        ),
                      ),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(
                        child: StatTile(
                          label: Format.tr('taxi.rides'),
                          value: Format.number(report.rideCount),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),

            if (report.unpaidCount > 0) ...[
              const SizedBox(height: AppSpacing.md),
              _UnpaidNotice(count: report.unpaidCount, amount: report.unpaidAmount),
            ],

            const SizedBox(height: AppSpacing.lg),
            Text(Format.tr('taxi.by_mode'), style: theme.textTheme.titleSmall),
            const SizedBox(height: AppSpacing.sm),
            if (report.byMode.isEmpty)
              EmptyState(icon: Icons.bar_chart_rounded, message: Format.tr('taxi.no_earnings'))
            else
              GlassCard(
                child: Column(
                  children: [
                    for (final mode in report.byMode)
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: Row(
                          children: [
                            Container(
                              width: 8,
                              height: 8,
                              decoration: BoxDecoration(
                                color: switch (mode.serviceType) {
                                  TaxiServiceType.line => AppColors.brand500,
                                  TaxiServiceType.charter => AppColors.warning,
                                  TaxiServiceType.meter => AppColors.info,
                                },
                                shape: BoxShape.circle,
                              ),
                            ),
                            const SizedBox(width: AppSpacing.sm),
                            Expanded(child: Text(mode.label, style: theme.textTheme.bodyMedium)),
                            Text(
                              Format.tr('taxi.rides_count', {
                                'count': Format.number(mode.rideCount),
                              }),
                              style: theme.textTheme.labelSmall,
                            ),
                            const SizedBox(width: AppSpacing.md),
                            Text(Format.money(mode.gross), style: theme.textTheme.titleSmall),
                          ],
                        ),
                      ),
                  ],
                ),
              ),

            if (report.byDay.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.lg),
              Text(Format.tr('taxi.by_day'), style: theme.textTheme.titleSmall),
              const SizedBox(height: AppSpacing.sm),
              GlassCard(
                child: Column(
                  children: [
                    for (final day in report.byDay.reversed.take(14))
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: Row(
                          children: [
                            Expanded(
                              child: Text(
                                Format.digits(day.date),
                                style: theme.textTheme.bodySmall,
                                textDirection: TextDirection.ltr,
                              ),
                            ),
                            Text(
                              Format.tr('taxi.rides_count', {
                                'count': Format.number(day.rideCount),
                              }),
                              style: theme.textTheme.labelSmall,
                            ),
                            const SizedBox(width: AppSpacing.md),
                            Text(Format.money(day.net), style: theme.textTheme.titleSmall),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
            ],

            const SizedBox(height: AppSpacing.lg),
            settlements.when(
              loading: () => const ShimmerBox(height: 120),
              error: (_, __) => const SizedBox.shrink(),
              data: (data) => _Settlements(
                items: data.items,
                pending: data.pending,
                busy: _busy,
                onRequest: _requestSettlement,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _UnpaidNotice extends StatelessWidget {
  const _UnpaidNotice({required this.count, required this.amount});

  final int count;
  final int amount;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.warning.withValues(alpha: 0.10),
        borderRadius: AppRadii.fieldBorder,
        border: Border.all(color: AppColors.warning.withValues(alpha: 0.28)),
      ),
      child: Row(
        children: [
          const Icon(Icons.error_outline_rounded, size: 18, color: AppColors.warning),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  Format.tr('taxi.unpaid_title', {'count': Format.number(count)}),
                  style: theme.textTheme.titleSmall?.copyWith(color: AppColors.warning),
                ),
                Text(
                  Format.tr('taxi.unpaid_body', {'amount': Format.money(amount)}),
                  style: theme.textTheme.labelSmall,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Settlements extends StatelessWidget {
  const _Settlements({
    required this.items,
    required this.pending,
    required this.busy,
    required this.onRequest,
  });

  final List<TaxiSettlement> items;
  final int pending;
  final bool busy;
  final VoidCallback onRequest;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        GlassCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(Format.tr('taxi.settlement'), style: theme.textTheme.titleSmall),
              const SizedBox(height: AppSpacing.sm),
              Text(Format.tr('taxi.settlement_body'), style: theme.textTheme.bodySmall),
              const SizedBox(height: AppSpacing.md),
              StatTile(
                label: Format.tr('taxi.settleable'),
                value: Format.money(pending),
                accent: AppColors.brand300,
              ),
              const SizedBox(height: AppSpacing.md),
              FilledButton.icon(
                onPressed: busy || pending <= 0 ? null : onRequest,
                style: FilledButton.styleFrom(backgroundColor: AppColors.warning),
                icon: const Icon(Icons.account_balance_outlined, size: 18),
                label: Text(Format.tr('taxi.request_settlement')),
              ),
            ],
          ),
        ),
        if (items.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.lg),
          Text(Format.tr('taxi.settlement_history'), style: theme.textTheme.titleSmall),
          const SizedBox(height: AppSpacing.sm),
          for (final settlement in items)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: GlassCard(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            Format.digits(settlement.reference),
                            style: theme.textTheme.titleSmall,
                            textDirection: TextDirection.ltr,
                          ),
                          Text(
                            Format.digits(
                              '${settlement.periodStart ?? ''} – ${settlement.periodEnd ?? ''}',
                            ),
                            style: theme.textTheme.labelSmall,
                          ),
                          if (settlement.rejectionReason != null)
                            Text(
                              settlement.rejectionReason!,
                              style: theme.textTheme.labelSmall
                                  ?.copyWith(color: AppColors.danger),
                            ),
                        ],
                      ),
                    ),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.end,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(settlement.formattedNet, style: theme.textTheme.titleSmall),
                        StatusBadge(
                          label: settlement.statusLabel,
                          colorToken: settlement.statusColor,
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
        ],
      ],
    );
  }
}
