import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

final _lineDetailProvider = FutureProvider.family<LineDetail, int>(
  (ref, id) => ref.watch(transitApiProvider).line(id),
);

final _routeDetailProvider = FutureProvider.family<RouteDetail, int>(
  (ref, id) => ref.watch(transitApiProvider).route(id),
);

/// The lines running in this city.
///
/// Signed-out like the map: someone deciding whether the network reaches them
/// at all should not have to create an account to find out.
class LinesScreen extends ConsumerWidget {
  const LinesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final lines = ref.watch(linesProvider);

    return AppScaffold(
      title: Format.tr('lines.title'),
      leading: const BackButton(),
      onRefresh: () async => ref.invalidate(linesProvider),
      body: lines.when(
        loading: () => ListView.separated(
          padding: const EdgeInsets.only(top: AppSpacing.md),
          itemCount: 5,
          separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
          itemBuilder: (_, __) => const ShimmerBox(height: 72),
        ),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('lines.load_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(linesProvider),
        ),
        data: (items) => items.isEmpty
            ? EmptyState(icon: Icons.route_outlined, message: Format.tr('lines.empty'))
            : ListView.separated(
                padding: const EdgeInsets.only(top: AppSpacing.md, bottom: 90),
                itemCount: items.length,
                separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
                itemBuilder: (_, index) => _LineTile(line: items[index]),
              ),
      ),
    );
  }
}

class _LineTile extends StatelessWidget {
  const _LineTile({required this.line});

  final BusLine line;

  Color get _color {
    final hex = line.color.replaceAll('#', '');

    if (hex.length != 6) return AppColors.brand500;

    return Color(int.parse('FF$hex', radix: 16));
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      onTap: () => Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => LineDetailScreen(line: line)),
      ),
      child: Row(
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(color: _color, borderRadius: BorderRadius.circular(13)),
            alignment: Alignment.center,
            child: Text(
              Format.digits(line.code),
              style:
                  const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: Colors.white),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(line.name, style: theme.textTheme.titleSmall, maxLines: 1),
                const SizedBox(height: 2),
                Text(
                  '${line.origin ?? '—'} ← ${line.destination ?? '—'}',
                  style: theme.textTheme.labelSmall,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                if (line.headwayMinutes != null)
                  Text(
                    Format.tr('plan.headway_note', {
                      'count': Format.number(line.headwayMinutes),
                    }),
                    style: theme.textTheme.labelSmall,
                  ),
              ],
            ),
          ),
          // Sample geometry is labelled everywhere it is shown, so nobody
          // plans a trip on a line the city never published.
          if (!line.isVerifiedData) const SampleDataNotice(compact: true),
        ],
      ),
    );
  }
}

/// One line: its directions, and the stops each one serves in order.
class LineDetailScreen extends ConsumerStatefulWidget {
  const LineDetailScreen({required this.line, super.key});

  final BusLine line;

  @override
  ConsumerState<LineDetailScreen> createState() => _LineDetailScreenState();
}

class _LineDetailScreenState extends ConsumerState<LineDetailScreen> {
  int? _routeId;

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(_lineDetailProvider(widget.line.id));

    return AppScaffold(
      title: Format.tr('lines.line_named', {'code': Format.digits(widget.line.code)}),
      subtitle: widget.line.name,
      leading: const BackButton(),
      body: detail.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.brand400)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('lines.load_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(_lineDetailProvider(widget.line.id)),
        ),
        data: (data) {
          if (data.routes.isEmpty) {
            return EmptyState(
              icon: Icons.route_outlined,
              message: Format.tr('lines.no_routes'),
            );
          }

          final selected = _routeId ?? data.routes.first.id;

          return ListView(
            padding: const EdgeInsets.only(top: AppSpacing.md, bottom: 90),
            children: [
              // A line's two directions are rarely mirror images, so which one
              // you are reading has to be an explicit choice.
              Wrap(
                spacing: AppSpacing.sm,
                children: [
                  for (final route in data.routes)
                    ChoiceChip(
                      label: Text(route.directionLabel.isEmpty ? route.name : route.directionLabel),
                      selected: route.id == selected,
                      onSelected: (_) => setState(() => _routeId = route.id),
                    ),
                ],
              ),
              const SizedBox(height: AppSpacing.lg),
              _RouteTimeline(routeId: selected),
            ],
          );
        },
      ),
    );
  }
}

class _RouteTimeline extends ConsumerWidget {
  const _RouteTimeline({required this.routeId});

  final int routeId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final route = ref.watch(_routeDetailProvider(routeId));
    final theme = Theme.of(context);

    return route.when(
      loading: () => Column(
        children: const [
          ShimmerBox(height: 56),
          SizedBox(height: AppSpacing.sm),
          ShimmerBox(height: 56),
          SizedBox(height: AppSpacing.sm),
          ShimmerBox(height: 56),
        ],
      ),
      error: (error, _) => ErrorState(
        message: error is ApiException ? error.message : Format.tr('lines.load_failed'),
        isOffline: error is NetworkException,
        onRetry: () => ref.invalidate(_routeDetailProvider(routeId)),
      ),
      data: (data) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            Format.tr('lines.stop_count', {
              'count': Format.number(data.stops.length),
              'distance': Format.distance(data.distanceMeters),
            }),
            style: theme.textTheme.labelSmall,
          ),
          const SizedBox(height: AppSpacing.md),
          for (var index = 0; index < data.stops.length; index++)
            _TimelineRow(
              item: data.stops[index],
              isFirst: index == 0,
              isLast: index == data.stops.length - 1,
            ),
        ],
      ),
    );
  }
}

class _TimelineRow extends ConsumerWidget {
  const _TimelineRow({required this.item, required this.isFirst, required this.isLast});

  final RouteStop item;
  final bool isFirst;
  final bool isLast;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);

    return InkWell(
      // Tapping a stop selects it on the map's arrival board, which is the
      // next thing anyone reading a route wants to know.
      onTap: () {
        ref.read(selectedStopProvider.notifier).state = item.stop;
        Navigator.of(context).popUntil((route) => route.isFirst);
      },
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: IntrinsicHeight(
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(
                width: 28,
                child: Column(
                  children: [
                    Expanded(
                      child: Container(
                        width: 2,
                        color: isFirst ? Colors.transparent : AppColors.ink600,
                      ),
                    ),
                    Container(
                      width: 11,
                      height: 11,
                      decoration: BoxDecoration(
                        color: isFirst || isLast ? AppColors.brand400 : AppColors.ink500,
                        shape: BoxShape.circle,
                      ),
                    ),
                    Expanded(
                      child: Container(
                        width: 2,
                        color: isLast ? Colors.transparent : AppColors.ink600,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 8),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          item.stop.name,
                          style: theme.textTheme.bodyMedium,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      if (item.stop.isAccessible)
                        const Icon(Icons.accessible_rounded, size: 14, color: AppColors.ink400),
                      const SizedBox(width: 6),
                      Text(
                        Format.distance(item.distanceFromStart),
                        style: theme.textTheme.labelSmall,
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
