import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../providers.dart';

/// Camera scanner for the rotating bus QR.
///
/// The token changes every 30 seconds and each one is single-use, so the
/// scanner must handle "expired" and "already used" as ordinary, recoverable
/// outcomes rather than errors: it simply keeps scanning and tells the user to
/// wait for the code on the screen to change.
class ScanScreen extends ConsumerStatefulWidget {
  const ScanScreen({this.prefilledToken, super.key});

  final String? prefilledToken;

  @override
  ConsumerState<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends ConsumerState<ScanScreen> {
  final _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    facing: CameraFacing.back,
  );

  bool _processing = false;
  String? _error;
  String? _lastAttemptedToken;

  @override
  void initState() {
    super.initState();

    if (widget.prefilledToken != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _board(widget.prefilledToken!));
    }
  }

  @override
  void dispose() {
    unawaited(_controller.dispose());
    super.dispose();
  }

  Future<void> _board(String token) async {
    if (_processing) return;

    // A stale token would fail anyway; don't burn a request on a re-read of
    // the exact code we just tried.
    if (token == _lastAttemptedToken && _error != null) return;

    setState(() {
      _processing = true;
      _error = null;
      _lastAttemptedToken = token;
    });

    final position = await ref.read(devicePositionProvider.future);

    try {
      final result = await ref.read(transitApiProvider).board(
            token: token,
            lat: position?.latitude,
            lng: position?.longitude,
          );

      if (!mounted) return;

      await _showSuccess(result);

      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (error) {
      if (!mounted) return;

      setState(() {
        _processing = false;
        _error = error.message;
      });

      // A stale or replayed code just needs another look at the screen.
      if (error.isStaleQr) {
        setState(() => _error = Format.tr('scan.expired'));
      }

      if (error.isInsufficientFunds && mounted) {
        await _showInsufficientFunds(error);
      }
    }
  }

  Future<void> _showSuccess(Map<String, dynamic> result) async {
    final fare = (result['fare'] as Map<String, dynamic>?) ?? const {};
    final balance = (result['balance'] as Map<String, dynamic>?) ?? const {};
    final trip = (result['trip'] as Map<String, dynamic>?) ?? const {};

    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        icon: const Icon(Icons.check_circle_rounded, color: AppColors.brand400, size: 44),
        title: Text(Format.tr('scan.boarded_title')),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              Format.tr('scan.fare_charged', {'amount': fare['formatted'] ?? '—'}),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: AppSpacing.md),
            Text(
              Format.tr('scan.new_balance', {'amount': balance['formatted'] ?? '—'}),
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (trip['destination'] != null) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(
                Format.tr('scan.destination', {'name': trip['destination']}),
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ],
          ],
        ),
        actions: [
          FilledButton(
            onPressed: () => Navigator.pop(context),
            child: Text(Format.tr('scan.ok')),
          ),
        ],
      ),
    );
  }

  Future<void> _showInsufficientFunds(ApiException error) async {
    final shortfall = error.details?['shortfall'];

    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        icon: const Icon(Icons.account_balance_wallet_outlined, color: AppColors.warning, size: 40),
        title: Text(Format.tr('scan.insufficient_title')),
        content: Text(
          shortfall is int
              ? Format.tr('scan.shortfall', {'amount': Format.money(shortfall)})
              : error.message,
          textAlign: TextAlign.center,
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context), child: Text(Format.tr('common.close'))),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.ink950,
      appBar: AppBar(
        title: Text(Format.tr('ride.scan_bus_code')),
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

              if (value != null) _board(value);
            },
            errorBuilder: (context, error, child) => ErrorState(
              message: Format.tr('scan.camera_denied'),
              onRetry: () => setState(() {}),
            ),
          ),

          // Viewfinder
          IgnorePointer(
            child: Center(
              child: Container(
                width: 240,
                height: 240,
                decoration: BoxDecoration(
                  border: Border.all(color: AppColors.brand400, width: 3),
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
                        const CircularProgressIndicator(color: AppColors.brand400),
                        const SizedBox(height: AppSpacing.md),
                        Text(Format.tr('scan.processing')),
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
                          onPressed: () => setState(() {
                            _error = null;
                            _lastAttemptedToken = null;
                          }),
                          child: Text(Format.tr('scan.retry')),
                        ),
                      ] else
                        Text(
                          Format.tr('scan.instruction'),
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
