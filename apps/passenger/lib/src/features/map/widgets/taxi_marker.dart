import 'package:flutter/material.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// A taxi on the map.
///
/// The fill is the product — a shared line, a charter or a metered ride — and
/// not the car's status, because "what is this one offering me" is the only
/// question a marker on a taxi map has to answer. The three colours are the
/// same everywhere: here, in the driver's app, and on the control room's
/// board.
class TaxiMarker extends StatelessWidget {
  const TaxiMarker({required this.taxi, this.onTap, super.key});

  final NearbyTaxi taxi;
  final VoidCallback? onTap;

  static Color colorFor(TaxiServiceType mode) => switch (mode) {
        TaxiServiceType.line => AppColors.brand500,
        TaxiServiceType.charter => AppColors.warning,
        TaxiServiceType.meter => AppColors.info,
      };

  @override
  Widget build(BuildContext context) {
    final color = colorFor(taxi.serviceType);

    return Semantics(
      button: onTap != null,
      label: taxi.serviceType.label,
      child: GestureDetector(
        onTap: onTap,
        // A full car is dimmed rather than hidden: knowing a taxi is there and
        // taken is still worth knowing.
        child: Opacity(
          opacity: taxi.isAvailable ? 1 : 0.4,
          child: Container(
            decoration: BoxDecoration(
              color: color,
              borderRadius: BorderRadius.circular(9),
              border: Border.all(color: Colors.white.withValues(alpha: 0.85), width: 2),
              boxShadow: const [
                BoxShadow(color: Color(0x80000000), blurRadius: 8, offset: Offset(0, 3)),
              ],
            ),
            child: const Icon(Icons.local_taxi_rounded, size: 15, color: Colors.white),
          ),
        ),
      ),
    );
  }
}

/// What a rider is told about a taxi they tapped.
///
/// No plate, no driver, no passenger count — the public feed does not carry
/// them, and it should not: a rider needs to know a car is there and what it
/// is offering, not who is driving it.
Future<void> showTaxiSheet(BuildContext context, NearbyTaxi taxi) {
  final color = TaxiMarker.colorFor(taxi.serviceType);

  return showModalBottomSheet<void>(
    context: context,
    backgroundColor: Colors.transparent,
    builder: (context) {
      final theme = Theme.of(context);

      return SafeArea(
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
              Row(
                children: [
                  Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(
                      color: color.withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Icon(Icons.local_taxi_rounded, color: color),
                  ),
                  const SizedBox(width: AppSpacing.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(taxi.serviceType.label, style: theme.textTheme.titleMedium),
                        if (taxi.lineName != null)
                          Text(taxi.lineName!, style: theme.textTheme.labelSmall),
                      ],
                    ),
                  ),
                  StatusBadge(
                    label: taxi.isAvailable
                        ? Format.tr('taxi_map.available')
                        : Format.tr('taxi_map.occupied'),
                    colorToken: taxi.isAvailable ? 'success' : 'warning',
                  ),
                ],
              ),
              const SizedBox(height: AppSpacing.lg),
              Row(
                children: [
                  if (taxi.distanceMeters != null)
                    Expanded(
                      child: StatTile(
                        label: Format.tr('taxi_map.distance'),
                        value: Format.distance(taxi.distanceMeters),
                      ),
                    ),
                  if (taxi.distanceMeters != null && taxi.seatsFree != null)
                    const SizedBox(width: AppSpacing.sm),
                  if (taxi.seatsFree != null && taxi.serviceType == TaxiServiceType.line)
                    Expanded(
                      child: StatTile(
                        label: Format.tr('taxi_map.seats_free'),
                        value: Format.number(taxi.seatsFree),
                        accent: AppColors.brand300,
                      ),
                    ),
                ],
              ),
              const SizedBox(height: AppSpacing.md),
              Text(
                Format.tr('taxi_map.how_to_pay.${taxi.serviceType.value}'),
                style: theme.textTheme.bodySmall,
              ),
            ],
          ),
        ),
      );
    },
  );
}
