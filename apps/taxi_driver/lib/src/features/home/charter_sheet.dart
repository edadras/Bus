import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// Naming the price for the hire standing at the window.
///
/// The figure is typed once, by the driver, and the passenger then confirms
/// exactly it — the server refuses any other amount. Presets exist because a
/// driver leaning out of a window is not going to type six digits.
class CharterSheet extends StatefulWidget {
  const CharterSheet({super.key});

  @override
  State<CharterSheet> createState() => _CharterSheetState();
}

class _CharterSheetState extends State<CharterSheet> {
  final _controller = TextEditingController();

  /// In minor units, as everything financial is. These are round fares for a
  /// short city hire, not a tariff — the driver overwrites them freely.
  static const _presets = [500000, 800000, 1200000, 2000000];

  int get _amount => int.tryParse(Format.toLatinDigits(_controller.text).trim()) ?? 0;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SafeArea(
        child: Container(
          margin: const EdgeInsets.all(AppSpacing.md),
          padding: const EdgeInsets.all(AppSpacing.lg),
          decoration: BoxDecoration(
            color: AppColors.ink850,
            borderRadius: AppRadii.cardBorder,
            border: Border.all(color: AppColors.glassBorderStrong),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(Format.tr('taxi.name_price'), style: theme.textTheme.titleMedium),
              const SizedBox(height: AppSpacing.sm),
              Text(Format.tr('taxi.name_price_body'), style: theme.textTheme.bodySmall),
              const SizedBox(height: AppSpacing.lg),
              TextField(
                controller: _controller,
                autofocus: true,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                textDirection: TextDirection.ltr,
                textAlign: TextAlign.center,
                style: theme.textTheme.headlineSmall,
                decoration: InputDecoration(
                  hintText: Format.tr('taxi.amount_hint'),
                  suffixText: Format.tr('unit.rial'),
                ),
                onChanged: (_) => setState(() {}),
              ),
              const SizedBox(height: AppSpacing.md),
              Wrap(
                spacing: AppSpacing.sm,
                runSpacing: AppSpacing.sm,
                children: [
                  for (final preset in _presets)
                    ActionChip(
                      label: Text(Format.money(preset)),
                      onPressed: () => setState(() => _controller.text = '$preset'),
                    ),
                ],
              ),
              const SizedBox(height: AppSpacing.lg),
              FilledButton(
                onPressed: _amount <= 0 ? null : () => Navigator.pop(context, _amount),
                style: FilledButton.styleFrom(backgroundColor: AppColors.warning),
                child: Text(Format.tr('taxi.set_price')),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
