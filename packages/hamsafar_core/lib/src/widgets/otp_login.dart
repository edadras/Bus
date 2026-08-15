import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_exception.dart';
import '../providers/core_providers.dart';
import '../theme/app_theme.dart';
import '../util/formatters.dart';
import 'glass.dart';

/// Two-step mobile sign-in, shared by all three apps.
///
/// The only difference between them is [client], which decides which Sanctum
/// abilities the issued token carries — a passenger build cannot mint a driver
/// token even if someone edits the request.
class OtpLoginView extends ConsumerStatefulWidget {
  const OtpLoginView({
    required this.client,
    required this.title,
    this.subtitle,
    this.icon = Icons.directions_bus_rounded,
    this.accent = AppColors.brand500,
    super.key,
  });

  final String client;
  final String title;
  final String? subtitle;
  final IconData icon;
  final Color accent;

  @override
  ConsumerState<OtpLoginView> createState() => _OtpLoginViewState();
}

class _OtpLoginViewState extends ConsumerState<OtpLoginView> {
  final _mobileController = TextEditingController();
  final _codeController = TextEditingController();

  bool _codeSent = false;
  String? _debugCode;
  String? _error;
  int _resendIn = 0;

  @override
  void dispose() {
    _mobileController.dispose();
    _codeController.dispose();
    super.dispose();
  }

  Future<void> _requestCode() async {
    final mobile = Format.normalizeMobile(_mobileController.text);

    if (!Format.isValidMobile(mobile)) {
      setState(() => _error = Format.tr('auth.invalid_mobile'));

      return;
    }

    setState(() => _error = null);

    try {
      final debugCode = await ref.read(authControllerProvider.notifier).requestOtp(mobile);

      if (!mounted) return;

      setState(() {
        _codeSent = true;
        _debugCode = debugCode;
        _resendIn = 60;
      });

      _tickResend();
    } on ApiException catch (error) {
      if (mounted) setState(() => _error = error.message);
    }
  }

  void _tickResend() {
    Future.delayed(const Duration(seconds: 1), () {
      if (!mounted || _resendIn <= 0) return;

      setState(() => _resendIn--);
      _tickResend();
    });
  }

  Future<void> _verify() async {
    setState(() => _error = null);

    try {
      await ref.read(authControllerProvider.notifier).verifyOtp(
            mobile: Format.normalizeMobile(_mobileController.text),
            code: Format.toLatinDigits(_codeController.text),
            client: widget.client,
          );
    } on ApiException catch (error) {
      if (!mounted) return;

      setState(() {
        _error = error.message;
        // A rejected client is a permission problem, not a wrong code, so send
        // the user back rather than letting them retype the same code.
        if (error.code == 'client_not_permitted') _codeSent = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final theme = Theme.of(context);

    return AppBackground(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(AppSpacing.xl),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Center(
                      child: Container(
                        width: 68,
                        height: 68,
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            colors: [widget.accent, widget.accent.withValues(alpha: 0.6)],
                          ),
                          borderRadius: BorderRadius.circular(22),
                        ),
                        child: Icon(widget.icon, size: 34, color: Colors.white),
                      ),
                    ),
                    const SizedBox(height: AppSpacing.xl),
                    Text(
                      widget.title,
                      textAlign: TextAlign.center,
                      style: theme.textTheme.headlineMedium,
                    ),
                    if (widget.subtitle != null) ...[
                      const SizedBox(height: AppSpacing.sm),
                      Text(
                        widget.subtitle!,
                        textAlign: TextAlign.center,
                        style: theme.textTheme.bodySmall,
                      ),
                    ],
                    const SizedBox(height: AppSpacing.xxl),
                    GlassCard(
                      strong: true,
                      child: _codeSent
                          ? _codeStep(theme, auth.isLoading)
                          : _mobileStep(theme, auth.isLoading),
                    ),
                    if (_error != null) ...[
                      const SizedBox(height: AppSpacing.md),
                      Text(
                        _error!,
                        textAlign: TextAlign.center,
                        style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _mobileStep(ThemeData theme, bool loading) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(Format.tr('auth.mobile_label'), style: theme.textTheme.labelMedium),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          controller: _mobileController,
          keyboardType: TextInputType.phone,
          textAlign: TextAlign.center,
          autofocus: true,
          style: const TextStyle(fontSize: 18, letterSpacing: 2, fontWeight: FontWeight.w600),
          inputFormatters: [LengthLimitingTextInputFormatter(15)],
          decoration: InputDecoration(hintText: Format.tr('auth.mobile_hint')),
          onSubmitted: (_) => _requestCode(),
        ),
        const SizedBox(height: AppSpacing.lg),
        FilledButton(
          onPressed: loading ? null : _requestCode,
          child: loading
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                )
              : Text(Format.tr('auth.request_code')),
        ),
      ],
    );
  }

  Widget _codeStep(ThemeData theme, bool loading) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          Format.tr('auth.code_sent_to', {'mobile': Format.digits(_mobileController.text)}),
          textAlign: TextAlign.center,
          style: theme.textTheme.bodySmall,
        ),
        const SizedBox(height: AppSpacing.lg),
        TextField(
          controller: _codeController,
          keyboardType: TextInputType.number,
          textAlign: TextAlign.center,
          autofocus: true,
          maxLength: 5,
          style: const TextStyle(fontSize: 26, letterSpacing: 14, fontWeight: FontWeight.w700),
          decoration: const InputDecoration(counterText: ''),
          onChanged: (value) {
            // Five digits is the whole code; submit without a second tap.
            if (Format.toLatinDigits(value).length == 5) _verify();
          },
        ),
        const SizedBox(height: AppSpacing.md),
        FilledButton(
          onPressed: loading ? null : _verify,
          child: loading
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                )
              : Text(Format.tr('auth.sign_in')),
        ),
        const SizedBox(height: AppSpacing.sm),
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            TextButton(
              onPressed: () => setState(() {
                _codeSent = false;
                _codeController.clear();
              }),
              child: Text(Format.tr('auth.change_number')),
            ),
            TextButton(
              onPressed: _resendIn > 0 ? null : _requestCode,
              child: Text(
                _resendIn > 0
                    ? Format.tr('auth.resend_in', {'seconds': Format.number(_resendIn)})
                    : Format.tr('auth.resend'),
              ),
            ),
          ],
        ),
        // Only ever populated by a non-production server, so the app is
        // testable without an SMS gateway.
        if (_debugCode != null) ...[
          const SizedBox(height: AppSpacing.sm),
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: AppColors.warning.withValues(alpha: 0.12),
              borderRadius: AppRadii.fieldBorder,
            ),
            child: Text(
              Format.tr('auth.debug_code', {'code': Format.digits(_debugCode!)}),
              textAlign: TextAlign.center,
              style: theme.textTheme.bodySmall?.copyWith(color: AppColors.warning),
            ),
          ),
        ],
      ],
    );
  }
}
