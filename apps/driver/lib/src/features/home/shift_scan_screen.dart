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
        'bus_not_assigned' => 'این اتوبوس به شما تخصیص داده نشده است. با مدیر ناوگان تماس بگیرید.',
        'driver_not_active' => 'حساب راننده شما هنوز تأیید نشده است.',
        'license_expired' => 'گواهینامه شما منقضی شده و امکان شروع شیفت وجود ندارد.',
        'contract_ended' => 'قرارداد همکاری شما به پایان رسیده است.',
        'city_mismatch' => 'این اتوبوس متعلق به شهر دیگری است.',
        'bus_already_in_service' => 'راننده دیگری روی این اتوبوس شیفت باز دارد.',
        'driver_already_on_shift' => 'شما روی اتوبوس دیگری شیفت باز دارید.',
        'bus_not_deployable' => 'این اتوبوس در وضعیت آماده سرویس نیست.',
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
          content: Text('شیفت روی اتوبوس ${Format.digits('${bus['bus_number']}')} آغاز شد.'),
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
        title: const Text('اسکن کد اتوبوس'),
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
            errorBuilder: (context, error, child) => const ErrorState(
              message: 'دسترسی به دوربین ممکن نیست. مجوز دوربین را در تنظیمات فعال کنید.',
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
                        const Text('در حال بررسی مجوز…'),
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
                          child: const Text('اسکن دوباره'),
                        ),
                      ] else
                        Text(
                          'کد QR نصب‌شده داخل اتوبوس را در کادر قرار دهید.',
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
