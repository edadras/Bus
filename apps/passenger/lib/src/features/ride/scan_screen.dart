import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../providers.dart';
import 'taxi_confirm_sheet.dart';

/// Camera scanner for the rotating fare codes.
///
/// One scanner for buses and taxis, because a passenger holding a phone at a
/// code does not know which subsystem issued it and should not have to. The
/// public id inside the token says which — `B…` for a bus, `X…` for a taxi —
/// and a wrong guess is recovered by trying the other one rather than shown as
/// a failure.
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
      WidgetsBinding.instance.addPostFrameCallback((_) => _scan(widget.prefilledToken!));
    }
  }

  @override
  void dispose() {
    unawaited(_controller.dispose());
    super.dispose();
  }

  /// Which subsystem issued this code, read from the public id inside it.
  ///
  /// Only a hint: it saves a wasted round trip in the common case, and the
  /// caller falls back to the other subsystem when the server says it has
  /// never seen the code.
  static bool _looksLikeTaxi(String token) {
    final parts = token.split('.');

    return parts.length > 1 && parts[1].startsWith('X');
  }

  Future<void> _scan(String token) async {
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

    final taxiFirst = _looksLikeTaxi(token);

    try {
      final handled = taxiFirst
          ? await _tryTaxi(token, position)
          : await _tryBus(token, position);

      // The prefix was misleading — a code the other subsystem owns. Try it
      // rather than telling the passenger their code does not exist.
      if (!handled && mounted) {
        final recovered = taxiFirst
            ? await _tryBus(token, position)
            : await _tryTaxi(token, position);

        if (!recovered && mounted) {
          setState(() {
            _processing = false;
            _error = Format.tr('scan.unknown_code');
          });
        }
      }
    } on ApiException catch (error) {
      if (!mounted) return;

      setState(() {
        _processing = false;
        // A stale or replayed code just needs another look at the screen.
        _error = error.isStaleQr ? Format.tr('scan.expired') : error.message;
      });

      if (error.isInsufficientFunds && mounted) {
        await _showInsufficientFunds(error);
      }
    }
  }

  /// Returns false when this is not a bus code, so the caller can try a taxi.
  Future<bool> _tryBus(String token, Position? position) async {
    try {
      final result = await ref.read(transitApiProvider).board(
            token: token,
            lat: position?.latitude,
            lng: position?.longitude,
          );

      if (!mounted) return true;

      await _showSuccess(result);

      if (mounted) Navigator.of(context).pop(true);

      return true;
    } on ApiException catch (error) {
      if (error.code == 'qr_unknown_code') return false;

      rethrow;
    }
  }

  /// Price the taxi ride, show the passenger what it costs, and take it only
  /// if they say so.
  ///
  /// The confirmed amount goes back to the server for the two priced-up-front
  /// modes, which is what makes charging a figure the passenger never saw
  /// impossible. A metered ride has no figure yet — what is confirmed there is
  /// the tariff.
  Future<bool> _tryTaxi(String token, Position? position) async {
    final TaxiScanResult quote;

    try {
      quote = await ref.read(transitApiProvider).scanTaxi(
            token: token,
            lat: position?.latitude,
            lng: position?.longitude,
          );
    } on ApiException catch (error) {
      if (error.code == 'qr_unknown_code') return false;

      rethrow;
    }

    if (!mounted) return true;

    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => TaxiConfirmSheet(quote: quote),
    );

    if (confirmed != true) {
      if (mounted) {
        setState(() {
          _processing = false;
          _lastAttemptedToken = null;
        });
      }

      return true;
    }

    final ride = await ref.read(transitApiProvider).takeTaxiRide(
          token: token,
          acceptedAmount:
              quote.requiresAmountConfirmation ? quote.quote.amount : null,
          lat: position?.latitude,
          lng: position?.longitude,
        );

    if (!mounted) return true;

    await _showTaxiStarted(ride);

    if (mounted) Navigator.of(context).pop(true);

    return true;
  }

  Future<void> _showTaxiStarted(TaxiRide ride) async {
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        icon: const Icon(Icons.local_taxi_rounded, color: AppColors.warning, size: 44),
        title: Text(
          ride.serviceType.isOpenEnded
              ? Format.tr('taxi_ride.meter_started')
              : Format.tr('taxi_ride.paid_title'),
        ),
        content: Text(
          ride.serviceType.isOpenEnded
              ? Format.tr('taxi_ride.meter_started_body')
              : Format.tr('scan.fare_charged', {'amount': ride.formattedFare}),
          textAlign: TextAlign.center,
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
        title: Text(Format.tr('ride.scan_fare_code')),
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
