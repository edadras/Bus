import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../../providers.dart';

/// Picks one end of a journey. Returns null when the sheet is dismissed, which
/// leaves whatever was already chosen alone.
Future<JourneyEndpoint?> showEndpointPicker(
  BuildContext context, {
  required String title,
  bool allowDevice = true,
}) {
  return showModalBottomSheet<JourneyEndpoint>(
    context: context,
    backgroundColor: Colors.transparent,
    isScrollControlled: true,
    builder: (context) => _EndpointPicker(title: title, allowDevice: allowDevice),
  );
}

class _EndpointPicker extends ConsumerStatefulWidget {
  const _EndpointPicker({required this.title, required this.allowDevice});

  final String title;
  final bool allowDevice;

  @override
  ConsumerState<_EndpointPicker> createState() => _EndpointPickerState();
}

class _EndpointPickerState extends ConsumerState<_EndpointPicker> {
  final _controller = TextEditingController();
  String _query = '';
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    _controller.dispose();
    super.dispose();
  }

  void _onChanged(String value) {
    // One request per pause in typing rather than one per keystroke: the stop
    // list is a server query, and a name is several letters long.
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 350), () {
      if (mounted) setState(() => _query = value);
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final results = ref.watch(stopSearchProvider(_query));

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: Container(
        height: MediaQuery.of(context).size.height * 0.78,
        decoration: const BoxDecoration(
          color: AppColors.ink850,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
          border: Border(top: BorderSide(color: AppColors.glassBorder)),
        ),
        padding: const EdgeInsets.fromLTRB(AppSpacing.lg, AppSpacing.md, AppSpacing.lg, 0),
        child: SafeArea(
          top: false,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 42,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppColors.ink600,
                    borderRadius: BorderRadius.circular(999),
                  ),
                ),
              ),
              const SizedBox(height: AppSpacing.lg),
              Text(widget.title, style: theme.textTheme.titleMedium),
              const SizedBox(height: AppSpacing.md),
              TextField(
                controller: _controller,
                onChanged: _onChanged,
                textInputAction: TextInputAction.search,
                decoration: InputDecoration(
                  hintText: Format.tr('plan.search_stop'),
                  prefixIcon: const Icon(Icons.search_rounded, size: 20),
                ),
              ),
              const SizedBox(height: AppSpacing.md),
              if (widget.allowDevice)
                GlassCard(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  onTap: () => Navigator.of(context).pop(const JourneyEndpoint.device()),
                  child: Row(
                    children: [
                      const Icon(Icons.my_location_rounded, size: 18, color: AppColors.brand300),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          Format.tr('plan.my_location'),
                          style: theme.textTheme.titleSmall,
                        ),
                      ),
                    ],
                  ),
                ),
              const SizedBox(height: AppSpacing.md),
              Expanded(
                child: results.when(
                  loading: () => ListView.separated(
                    itemCount: 4,
                    separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
                    itemBuilder: (_, __) => const ShimmerBox(height: 56),
                  ),
                  error: (error, _) => ErrorState(
                    message: error is ApiException ? error.message : Format.tr('map.load_failed'),
                    isOffline: error is NetworkException,
                    onRetry: () => ref.invalidate(stopSearchProvider(_query)),
                  ),
                  data: (stops) => stops.isEmpty
                      ? EmptyState(
                          icon: Icons.location_off_outlined,
                          message: _query.trim().isEmpty
                              ? Format.tr('plan.no_stops_nearby')
                              : Format.tr('plan.no_stops_match'),
                        )
                      : ListView.separated(
                          padding: const EdgeInsets.only(bottom: AppSpacing.xl),
                          itemCount: stops.length,
                          separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
                          itemBuilder: (_, index) => _StopRow(stop: stops[index]),
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

class _StopRow extends StatelessWidget {
  const _StopRow({required this.stop});

  final BusStop stop;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      onTap: () => Navigator.of(context).pop(JourneyEndpoint.stop(stop)),
      child: Row(
        children: [
          Icon(
            stop.isTerminal ? Icons.flag_rounded : Icons.location_on_outlined,
            size: 18,
            color: AppColors.ink300,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  stop.name,
                  style: theme.textTheme.titleSmall,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                if (stop.code.isNotEmpty)
                  Text(Format.digits(stop.code), style: theme.textTheme.labelSmall),
              ],
            ),
          ),
          // Community-sourced geometry is labelled wherever it is offered as a
          // choice, so a passenger never takes sample data for the real network.
          if (!stop.isVerifiedData) const SampleDataNotice(compact: true),
          if (stop.distanceMeters != null) ...[
            const SizedBox(width: 8),
            Text(Format.distance(stop.distanceMeters), style: theme.textTheme.labelSmall),
          ],
        ],
      ),
    );
  }
}
