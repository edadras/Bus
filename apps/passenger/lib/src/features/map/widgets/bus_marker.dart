import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// A bus on the map: a coloured disc that rotates to the vehicle's heading and
/// carries its line code. A bus whose telemetry has gone quiet is faded rather
/// than removed, so the map never appears to lose vehicles at random.
class BusMarker extends StatelessWidget {
  const BusMarker({required this.bus, required this.onTap, super.key});

  final LiveBus bus;
  final VoidCallback onTap;

  Color get _color {
    final hex = bus.lineColor?.replaceAll('#', '');

    if (hex == null || hex.length != 6) return AppColors.brand500;

    return Color(int.parse('FF$hex', radix: 16));
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Opacity(
        opacity: bus.isStale ? 0.45 : 1,
        child: Stack(
          alignment: Alignment.center,
          children: [
            if (!bus.isStale)
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: _color.withValues(alpha: 0.18),
                  shape: BoxShape.circle,
                ),
              ),
            Transform.rotate(
              angle: (bus.heading ?? 0) * math.pi / 180,
              child: Container(
                width: 28,
                height: 28,
                decoration: BoxDecoration(
                  color: _color,
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white.withValues(alpha: 0.85), width: 2),
                  boxShadow: const [
                    BoxShadow(color: Color(0x80000000), blurRadius: 8, offset: Offset(0, 3)),
                  ],
                ),
                child: Transform.rotate(
                  angle: -(bus.heading ?? 0) * math.pi / 180,
                  child: const Icon(Icons.directions_bus_rounded, size: 14, color: Colors.white),
                ),
              ),
            ),
            if (bus.lineCode != null)
              Positioned(
                top: -2,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                  decoration: BoxDecoration(
                    color: AppColors.ink950.withValues(alpha: 0.9),
                    borderRadius: BorderRadius.circular(999),
                    border: Border.all(color: Colors.white24),
                  ),
                  child: Text(
                    Format.digits(bus.lineCode!),
                    style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w700),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// A stop on the map. Terminals are drawn larger and filled, because they are
/// the anchors people navigate by.
class StopMarker extends StatelessWidget {
  const StopMarker({required this.stop, required this.onTap, super.key});

  final BusStop stop;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final size = stop.isTerminal ? 16.0 : 11.0;

    return GestureDetector(
      onTap: onTap,
      // The visual dot is small; the touch target must not be.
      behavior: HitTestBehavior.opaque,
      child: Center(
        child: Container(
          width: size,
          height: size,
          decoration: BoxDecoration(
            color: stop.isTerminal ? AppColors.brand400 : AppColors.ink850,
            shape: BoxShape.circle,
            border: Border.all(
              color: stop.isTerminal ? Colors.white : AppColors.brand400,
              width: 2,
            ),
          ),
        ),
      ),
    );
  }
}
