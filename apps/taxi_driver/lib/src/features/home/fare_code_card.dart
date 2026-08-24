import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../providers.dart';

/// The code passengers scan.
///
/// It is not a taxi identifier. Each token carries a time step and a
/// single-use nonce, so a photograph of this screen is worthless a minute
/// later and cannot be passed to somebody in another car. The countdown is
/// there so a driver can tell a passenger to wait a second rather than
/// wondering why a scan failed.
class FareCodeCard extends ConsumerStatefulWidget {
  const FareCodeCard({required this.qr, required this.serviceType, super.key});

  final TaxiQrToken? qr;
  final TaxiServiceType serviceType;

  @override
  ConsumerState<FareCodeCard> createState() => _FareCodeCardState();
}

class _FareCodeCardState extends ConsumerState<FareCodeCard> {
  Timer? _timer;
  late int _remaining = widget.qr?.expiresIn ?? 0;

  @override
  void initState() {
    super.initState();
    _start();
  }

  @override
  void didUpdateWidget(FareCodeCard oldWidget) {
    super.didUpdateWidget(oldWidget);

    if (oldWidget.qr?.token != widget.qr?.token) {
      _remaining = widget.qr?.expiresIn ?? 0;
      _start();
    }
  }

  void _start() {
    _timer?.cancel();

    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (!mounted) return;

      // At zero the token on screen has rotated server-side; pulling the
      // state refreshes it rather than leaving a dead code up.
      if (_remaining <= 1) {
        ref.invalidate(taxiStateProvider);
      }

      setState(() => _remaining = (_remaining - 1).clamp(0, 120));
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final qr = widget.qr;

    if (qr == null) {
      return EmptyState(icon: Icons.qr_code_2_rounded, message: Format.tr('taxi.no_code'));
    }

    final total = qr.rotationSeconds == 0 ? 30 : qr.rotationSeconds;
    final progress = (_remaining / total).clamp(0.0, 1.0);

    return GlassCard(
      strong: true,
      padding: const EdgeInsets.all(AppSpacing.xl),
      child: Column(
        children: [
          Text(Format.tr('taxi.fare_code'), style: theme.textTheme.titleSmall),
          const SizedBox(height: AppSpacing.sm),
          Text(
            Format.tr('taxi.fare_code_body.${widget.serviceType.value}'),
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: AppSpacing.xl),
          // A white quiet zone: scanners struggle badly with a tinted QR
          // background, and half the phones that will try are cheap ones.
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(20),
            ),
            child: QrImageView(
              data: qr.token,
              version: QrVersions.auto,
              size: 220,
              gapless: true,
              errorCorrectionLevel: QrErrorCorrectLevel.M,
            ),
          ),
          const SizedBox(height: AppSpacing.lg),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 6,
              backgroundColor: AppColors.ink700,
              color: progress < 0.25 ? AppColors.warning : AppColors.brand400,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            Format.tr('collect.code_validity', {'seconds': Format.number(_remaining)}),
            style: theme.textTheme.labelSmall,
          ),
          const SizedBox(height: AppSpacing.sm),
          Text(
            Format.tr('taxi.code_id', {'id': qr.publicId}),
            style: theme.textTheme.labelSmall,
            textDirection: TextDirection.ltr,
          ),
        ],
      ),
    );
  }
}
