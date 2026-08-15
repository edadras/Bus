import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

/// Scanning the in-bus code to open a shift.
///
/// The refusals here are all authorisation decisions made by the server — an
/// unassigned bus, an expired licence, another driver already on that vehicle —
/// so each one is shown with its own explanation rather than a generic failure.
class ShiftScanScreen extends ConsumerStatefulWidget {
  const ShiftScanScreen({super.key});

  @override
  ConsumerState<ShiftScanScreen> createState() => _ShiftScanScreenState();
}

class _ShiftScanScreenState extends ConsumerState<ShiftScanScreen> {
  final _controller = MobileScannerController(detectionSpeed: DetectionSpeed.noDuplicates);

  bool _processing = false;
  String? _error;

  @override
  void dispose() {
    unawaited(_controller.dispose());
    super.dispose();
  }

  String _explain(ApiException error) => switch (error.code) {
        'bus_not_assigned' => Format.tr('shift_scan.bus_not_assigned'),
        'driver_not_active' => Format.tr('shift_scan.driver_not_active'),
        'license_expired' => Format.tr('shift_scan.license_expired'),
        'contract_ended' => Format.tr('shift_scan.contract_ended'),
        'city_mismatch' => Format.tr('shift_scan.city_mismatch'),
        'bus_already_in_service' => Format.tr('shift_scan.bus_already_in_service'),
        'driver_already_on_shift' => Format.tr('shift_scan.driver_already_on_shift'),
        'bus_not_deployable' => Format.tr('shift_scan.bus_not_deployable'),
        _ => error.message,
      };

  Future<void> _scan(String token) async {
    if (_processing) return;

    setState(() {
      _processing = true;
      _error = null;
    });

    try {
      final position = await Geolocator.getLastKnownPosition();

      final result = await ref.read(transitApiProvider).startShift(
            token: token,
            lat: position?.latitude,
            lng: position?.longitude,
          );

      if (!mounted) return;

      final bus = (result['bus'] as Map<String, dynamic>?) ?? const {};

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            Format.tr('shift_scan.started', {'number': Format.digits('${bus['bus_number']}')}),
          ),
        ),
      );

      Navigator.of(context).pop(true);
    } on ApiException catch (error) {
      if (mounted) {
        setState(() {
          _processing = false;
          _error = _explain(error);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.ink950,
      appBar: AppBar(
        title: Text(Format.tr('shift_scan.title')),
        actions: [
          IconButton(
            onPressed: () => _controller.toggleTorch(),
            icon: const Icon(Icons.flashlight_on_outlined),
          ),
        ],
      ),
      body: Stack(
        children: [
          MobileScanner(
            controller: _controller,
            onDetect: (capture) {
              final value = capture.barcodes.firstOrNull?.rawValue;

              if (value != null) _scan(value);
            },
            errorBuilder: (context, error, child) => ErrorState(
              message: Format.tr('scan.camera_denied'),
            ),
          ),
          IgnorePointer(
            child: Center(
              child: Container(
                width: 250,
                height: 250,
                decoration: BoxDecoration(
                  border: Border.all(color: AppColors.info, width: 3),
                  borderRadius: BorderRadius.circular(24),
                ),
              ),
            ),
          ),
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.lg),
                child: GlassCard(
                  strong: true,
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (_processing) ...[
                        const CircularProgressIndicator(color: AppColors.info),
                        const SizedBox(height: AppSpacing.md),
                        Text(Format.tr('shift_scan.checking_permission')),
                      ] else if (_error != null) ...[
                        Text(
                          _error!,
                          textAlign: TextAlign.center,
                          style: Theme.of(context)
                              .textTheme
                              .bodyMedium
                              ?.copyWith(color: AppColors.warning),
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        TextButton(
                          onPressed: () => setState(() => _error = null),
                          child: Text(Format.tr('scan.retry')),
                        ),
                      ] else
                        Text(
                          Format.tr('shift_scan.instruction'),
                          textAlign: TextAlign.center,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
