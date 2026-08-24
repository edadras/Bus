import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// Opening a shift: which car, and what it is offering.
///
/// Both are asked here because both are required to take a single fare, and a
/// car on the street with no mode set is a car nobody can pay.
class StartShiftSheet extends ConsumerStatefulWidget {
  const StartShiftSheet({required this.taxis, super.key});

  final List<AssignedTaxi> taxis;

  @override
  ConsumerState<StartShiftSheet> createState() => _StartShiftSheetState();
}

class _StartShiftSheetState extends ConsumerState<StartShiftSheet> {
  late AssignedTaxi _taxi = widget.taxis.first;
  TaxiServiceType? _mode;
  int? _lineId;
  bool _busy = false;
  String? _error;

  List<TaxiServiceType> get _modes =>
      _taxi.allowedModes.isEmpty ? TaxiServiceType.values : _taxi.allowedModes;

  bool get _ready =>
      _mode != null && (_mode != TaxiServiceType.line || _lineId != null);

  @override
  void initState() {
    super.initState();

    _mode = _modes.first;
    _lineId = _taxi.defaultLineId;
  }

  Future<void> _start() async {
    if (!_ready) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final position = await Geolocator.getLastKnownPosition();

      await ref.read(transitApiProvider).startTaxiShift(
            taxiUuid: _taxi.uuid,
            serviceType: _mode!.value,
            taxiLineId: _mode == TaxiServiceType.line ? _lineId : null,
            lat: position?.latitude,
            lng: position?.longitude,
          );

      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (error) {
      if (mounted) {
        setState(() {
          _error = error.message;
          _busy = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final lines = ref.watch(taxiLinesProvider);

    return SafeArea(
      child: Container(
        margin: const EdgeInsets.all(AppSpacing.md),
        padding: const EdgeInsets.all(AppSpacing.lg),
        constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.85),
        decoration: BoxDecoration(
          color: AppColors.ink850,
          borderRadius: AppRadii.cardBorder,
          border: Border.all(color: AppColors.glassBorderStrong),
        ),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(Format.tr('taxi.start_shift'), style: theme.textTheme.titleMedium),
              const SizedBox(height: AppSpacing.lg),

              if (widget.taxis.length > 1) ...[
                Text(Format.tr('taxi.choose_car'), style: theme.textTheme.labelMedium),
                const SizedBox(height: AppSpacing.sm),
                for (final taxi in widget.taxis)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: _CarTile(
                      taxi: taxi,
                      selected: taxi.uuid == _taxi.uuid,
                      onTap: () => setState(() {
                        _taxi = taxi;
                        _mode = _modes.first;
                        _lineId = taxi.defaultLineId;
                      }),
                    ),
                  ),
                const SizedBox(height: AppSpacing.md),
              ] else
                _CarTile(taxi: _taxi, selected: true, onTap: null),

              const SizedBox(height: AppSpacing.lg),
              Text(Format.tr('taxi.choose_mode'), style: theme.textTheme.labelMedium),
              const SizedBox(height: AppSpacing.sm),
              for (final mode in _modes)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: _ModeTile(
                    mode: mode,
                    selected: mode == _mode,
                    onTap: () => setState(() => _mode = mode),
                  ),
                ),

              if (_mode == TaxiServiceType.line) ...[
                const SizedBox(height: AppSpacing.md),
                Text(Format.tr('taxi.choose_line'), style: theme.textTheme.labelMedium),
                const SizedBox(height: AppSpacing.sm),
                lines.when(
                  loading: () => const ShimmerBox(height: 52),
                  error: (_, __) => Text(
                    Format.tr('taxi.lines_failed'),
                    style: theme.textTheme.bodySmall,
                  ),
                  data: (items) => items.isEmpty
                      ? Text(Format.tr('taxi.no_lines'), style: theme.textTheme.bodySmall)
                      : DropdownButtonFormField<int>(
                          initialValue:
                              items.any((line) => line.id == _lineId) ? _lineId : null,
                          dropdownColor: AppColors.ink800,
                          isExpanded: true,
                          hint: Text(Format.tr('taxi.choose_line')),
                          items: [
                            for (final line in items)
                              DropdownMenuItem(
                                value: line.id,
                                child: Text(
                                  Format.digits('${line.code} — ${line.name}'),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                          ],
                          onChanged: (value) => setState(() => _lineId = value),
                        ),
                ),
              ],

              if (_error != null) ...[
                const SizedBox(height: AppSpacing.md),
                Text(
                  _error!,
                  style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger),
                ),
              ],

              const SizedBox(height: AppSpacing.lg),
              FilledButton.icon(
                onPressed: _busy || !_ready ? null : _start,
                style: FilledButton.styleFrom(backgroundColor: AppColors.warning),
                icon: const Icon(Icons.play_arrow_rounded),
                label: Text(
                  _busy ? Format.tr('taxi.starting') : Format.tr('taxi.start_shift'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CarTile extends StatelessWidget {
  const _CarTile({required this.taxi, required this.selected, required this.onTap});

  final AssignedTaxi taxi;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return InkWell(
      onTap: onTap,
      borderRadius: AppRadii.fieldBorder,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: selected ? AppColors.warning.withValues(alpha: 0.12) : AppColors.glassFill,
          borderRadius: AppRadii.fieldBorder,
          border: Border.all(
            color: selected ? AppColors.warning : AppColors.glassBorder,
          ),
        ),
        child: Row(
          children: [
            const Icon(Icons.local_taxi_rounded, size: 18, color: AppColors.ink300),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    Format.tr('taxi.car_number', {'number': Format.digits(taxi.number)}),
                    style: theme.textTheme.titleSmall,
                  ),
                  Text(
                    Format.digits([taxi.plate, taxi.model].whereType<String>().join(' · ')),
                    style: theme.textTheme.labelSmall,
                    textDirection: TextDirection.ltr,
                  ),
                ],
              ),
            ),
            if (selected)
              const Icon(Icons.check_circle_rounded, size: 18, color: AppColors.warning),
          ],
        ),
      ),
    );
  }
}

class _ModeTile extends StatelessWidget {
  const _ModeTile({required this.mode, required this.selected, required this.onTap});

  final TaxiServiceType mode;
  final bool selected;
  final VoidCallback onTap;

  Color get _color => switch (mode) {
        TaxiServiceType.line => AppColors.brand500,
        TaxiServiceType.charter => AppColors.warning,
        TaxiServiceType.meter => AppColors.info,
      };

  IconData get _icon => switch (mode) {
        TaxiServiceType.line => Icons.alt_route_rounded,
        TaxiServiceType.charter => Icons.handshake_outlined,
        TaxiServiceType.meter => Icons.speed_rounded,
      };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return InkWell(
      onTap: onTap,
      borderRadius: AppRadii.fieldBorder,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: selected ? _color.withValues(alpha: 0.14) : AppColors.glassFill,
          borderRadius: AppRadii.fieldBorder,
          border: Border.all(color: selected ? _color : AppColors.glassBorder),
        ),
        child: Row(
          children: [
            Icon(_icon, size: 20, color: selected ? _color : AppColors.ink400),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(mode.label, style: theme.textTheme.titleSmall),
                  Text(
                    Format.tr('taxi.mode_help.${mode.value}'),
                    style: theme.textTheme.labelSmall,
                  ),
                ],
              ),
            ),
            if (selected) Icon(Icons.check_circle_rounded, size: 18, color: _color),
          ],
        ),
      ),
    );
  }
}
