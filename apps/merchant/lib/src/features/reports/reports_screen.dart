import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// Daily sales, drawn as a simple bar chart.
///
/// The chart is hand-drawn with a CustomPainter rather than pulled from a
/// charting package: it is one series of at most thirty bars, and the package
/// would cost more in binary size than the feature is worth.
class ReportsScreen extends ConsumerWidget {
  const ReportsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final merchant = ref.watch(merchantStateProvider).valueOrNull;
    final report = ref.watch(salesReportProvider);
    final theme = Theme.of(context);

    if (merchant != null && !merchant.canViewReports) {
      return AppScaffold(
        title: Format.tr('reports.title'),
        body: EmptyState(
          icon: Icons.lock_outline_rounded,
          message: Format.tr('reports.no_access'),
        ),
      );
    }

    return AppScaffold(
      title: Format.tr('reports.title'),
      onRefresh: () async {
        ref.invalidate(salesReportProvider);
        await ref.read(salesReportProvider.future);
      },
      body: report.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.warning)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('reports.load_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(salesReportProvider),
        ),
        data: (data) {
          final totals = (data['totals'] as Map<String, dynamic>?) ?? const {};
          final daily =
              ((data['daily'] as List<dynamic>?) ?? const []).cast<Map<String, dynamic>>();

          return ListView(
            padding: const EdgeInsets.only(bottom: 110),
            children: [
              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: Format.tr('reports.gross'),
                      value: Format.money((totals['gross'] as num?)?.toInt()),
                      accent: AppColors.brand300,
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: StatTile(
                      label: Format.tr('reports.commission'),
                      value: Format.money((totals['commission'] as num?)?.toInt()),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.sm),
              Row(
                children: [
                  Expanded(
                    child: StatTile(
                      label: Format.tr('reports.net'),
                      value: Format.money((totals['net'] as num?)?.toInt()),
                    ),
                  ),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: StatTile(
                      label: Format.tr('reports.transaction_count'),
                      value: Format.number((totals['transactions'] as num?)?.toInt() ?? 0),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.lg),
              GlassCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(Format.tr('reports.daily_sales'), style: theme.textTheme.titleSmall),
                    const SizedBox(height: AppSpacing.lg),
                    if (daily.isEmpty)
                      EmptyState(message: Format.tr('reports.no_data'))
                    else
                      SizedBox(
                        height: 160,
                        child: CustomPaint(
                          painter: _BarChartPainter(
                            values: [
                              for (final row in daily) (row['gross'] as num?)?.toDouble() ?? 0,
                            ],
                          ),
                          child: const SizedBox.expand(),
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.lg),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: Text(Format.tr('reports.daily_detail'), style: theme.textTheme.titleSmall),
              ),
              const SizedBox(height: AppSpacing.sm),
              for (final row in daily.reversed) ...[
                GlassCard(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          Format.digits(row['day']?.toString() ?? '—'),
                          style: theme.textTheme.titleSmall,
                          textDirection: TextDirection.ltr,
                        ),
                      ),
                      Text(
                        Format.tr('reports.transactions_count', {
                          'count': Format.number((row['transactions'] as num?)?.toInt() ?? 0),
                        }),
                        style: theme.textTheme.labelSmall,
                      ),
                      const SizedBox(width: 12),
                      Text(
                        Format.money((row['net'] as num?)?.toInt()),
                        style: theme.textTheme.titleSmall?.copyWith(color: AppColors.brand300),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
              ],
            ],
          );
        },
      ),
    );
  }
}

class _BarChartPainter extends CustomPainter {
  const _BarChartPainter({required this.values});

  final List<double> values;

  @override
  void paint(Canvas canvas, Size size) {
    if (values.isEmpty) return;

    final maximum = values.reduce((a, b) => a > b ? a : b);

    // A flat-zero series would divide by zero; draw an empty axis instead.
    if (maximum <= 0) return;

    final barWidth = size.width / (values.length * 1.6);
    final gap = barWidth * 0.6;

    final paint = Paint()
      ..shader = const LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: [AppColors.brand400, AppColors.brand700],
      ).createShader(Rect.fromLTWH(0, 0, size.width, size.height));

    // RTL: the earliest day belongs on the right.
    for (var index = 0; index < values.length; index++) {
      final height = (values[index] / maximum) * (size.height - 8);
      final right = size.width - index * (barWidth + gap);

      canvas.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTWH(right - barWidth, size.height - height, barWidth, height),
          const Radius.circular(4),
        ),
        paint,
      );
    }
  }

  @override
  bool shouldRepaint(_BarChartPainter oldDelegate) => oldDelegate.values != values;
}
