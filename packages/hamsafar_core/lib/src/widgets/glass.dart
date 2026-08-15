import 'dart:ui';

import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// The core surface of the design system: a translucent fill, a lit top edge
/// and a backdrop blur. All three together — the blur alone reads as fog and
/// the fill alone reads as flat grey.
class GlassCard extends StatelessWidget {
  const GlassCard({
    required this.child,
    this.padding = const EdgeInsets.all(AppSpacing.lg),
    this.margin,
    this.strong = false,
    this.onTap,
    this.borderRadius,
    this.borderColor,
    super.key,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final EdgeInsetsGeometry? margin;
  final bool strong;
  final VoidCallback? onTap;
  final BorderRadius? borderRadius;
  final Color? borderColor;

  @override
  Widget build(BuildContext context) {
    final radius = borderRadius ?? AppRadii.cardBorder;

    final surface = ClipRRect(
      borderRadius: radius,
      child: BackdropFilter(
        // Blur is expensive; kept modest so a list of these still scrolls at
        // 60fps on a mid-range Android device.
        filter: ImageFilter.blur(sigmaX: 14, sigmaY: 14),
        child: DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topRight,
              end: Alignment.bottomLeft,
              colors: strong
                  ? const [Color(0x1FFFFFFF), Color(0x0BFFFFFF)]
                  : const [Color(0x14FFFFFF), Color(0x06FFFFFF)],
            ),
            borderRadius: radius,
            border: Border.all(
              color: borderColor ?? (strong ? AppColors.glassBorderStrong : AppColors.glassBorder),
            ),
          ),
          child: Padding(padding: padding, child: child),
        ),
      ),
    );

    return Container(
      margin: margin,
      child: onTap == null
          ? surface
          : Material(
              color: Colors.transparent,
              child: InkWell(
                onTap: onTap,
                borderRadius: radius,
                child: surface,
              ),
            ),
    );
  }
}

/// Small status pill, coloured from the API's own colour token so the app and
/// the admin panel always agree on what "warning" looks like.
class StatusBadge extends StatelessWidget {
  const StatusBadge({
    required this.label,
    this.colorToken = 'neutral',
    this.icon,
    this.compact = false,
    super.key,
  });

  final String label;
  final String colorToken;
  final IconData? icon;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final color = AppColors.forToken(colorToken);

    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: compact ? 8 : 10,
        vertical: compact ? 3 : 5,
      ),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.15),
        borderRadius: AppRadii.pillBorder,
        border: Border.all(color: color.withValues(alpha: 0.3)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: compact ? 11 : 13, color: color),
            const SizedBox(width: 4),
          ],
          Text(
            label,
            style: TextStyle(
              fontSize: compact ? 10 : 11.5,
              fontWeight: FontWeight.w600,
              color: color,
            ),
          ),
        ],
      ),
    );
  }
}

/// The pulsing dot that marks anything genuinely live.
class LiveDot extends StatefulWidget {
  const LiveDot({this.color = AppColors.brand400, this.size = 8, this.animate = true, super.key});

  final Color color;
  final double size;
  final bool animate;

  @override
  State<LiveDot> createState() => _LiveDotState();
}

class _LiveDotState extends State<LiveDot> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1800),
  );

  @override
  void initState() {
    super.initState();

    if (widget.animate) _controller.repeat();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Honour the platform's reduce-motion setting: a map is motion enough.
    final reduceMotion = MediaQuery.maybeDisableAnimationsOf(context) ?? false;

    if (!widget.animate || reduceMotion) {
      return _dot();
    }

    return AnimatedBuilder(
      animation: _controller,
      builder: (context, child) => SizedBox(
        width: widget.size * 2.6,
        height: widget.size * 2.6,
        child: Stack(
          alignment: Alignment.center,
          children: [
            Opacity(
              opacity: (1 - _controller.value).clamp(0.0, 1.0) * 0.55,
              child: Transform.scale(
                scale: 1 + _controller.value * 1.6,
                child: _dot(),
              ),
            ),
            _dot(),
          ],
        ),
      ),
    );
  }

  Widget _dot() => Container(
        width: widget.size,
        height: widget.size,
        decoration: BoxDecoration(color: widget.color, shape: BoxShape.circle),
      );
}

/// Loading placeholder that shimmers rather than spinning, so a list keeps its
/// shape while it loads and does not jump when data arrives.
class ShimmerBox extends StatefulWidget {
  const ShimmerBox({this.height = 64, this.width, this.radius = 14, super.key});

  final double height;
  final double? width;
  final double radius;

  @override
  State<ShimmerBox> createState() => _ShimmerBoxState();
}

class _ShimmerBoxState extends State<ShimmerBox> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1400),
  )..repeat();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) => Container(
        height: widget.height,
        width: widget.width,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(widget.radius),
          gradient: LinearGradient(
            begin: Alignment(-1 + _controller.value * 2, 0),
            end: Alignment(1 + _controller.value * 2, 0),
            colors: const [Color(0x0AFFFFFF), Color(0x1AFFFFFF), Color(0x0AFFFFFF)],
          ),
        ),
      ),
    );
  }
}

/// Consistent empty state: an icon, a sentence, and optionally one action.
class EmptyState extends StatelessWidget {
  const EmptyState({
    required this.message,
    this.icon = Icons.inbox_outlined,
    this.actionLabel,
    this.onAction,
    super.key,
  });

  final String message;
  final IconData icon;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.xxl, horizontal: AppSpacing.lg),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 56,
            height: 56,
            decoration: const BoxDecoration(color: AppColors.glassFill, shape: BoxShape.circle),
            child: Icon(icon, color: AppColors.ink400, size: 26),
          ),
          const SizedBox(height: AppSpacing.lg),
          Text(
            message,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: AppColors.ink400),
          ),
          if (actionLabel != null && onAction != null) ...[
            const SizedBox(height: AppSpacing.lg),
            OutlinedButton(onPressed: onAction, child: Text(actionLabel!)),
          ],
        ],
      ),
    );
  }
}

/// Error state that distinguishes "you are offline" from "the server said no",
/// because the user's next action differs.
class ErrorState extends StatelessWidget {
  const ErrorState({required this.message, this.onRetry, this.isOffline = false, super.key});

  final String message;
  final VoidCallback? onRetry;
  final bool isOffline;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(AppSpacing.xl),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            isOffline ? Icons.wifi_off_rounded : Icons.error_outline_rounded,
            color: isOffline ? AppColors.ink400 : AppColors.danger,
            size: 34,
          ),
          const SizedBox(height: AppSpacing.md),
          Text(
            message,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium,
          ),
          if (onRetry != null) ...[
            const SizedBox(height: AppSpacing.lg),
            OutlinedButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh_rounded, size: 18),
              label: const Text('تلاش دوباره'),
            ),
          ],
        ],
      ),
    );
  }
}

/// A labelled figure, used across all three apps' dashboards.
class StatTile extends StatelessWidget {
  const StatTile({
    required this.label,
    required this.value,
    this.accent,
    this.caption,
    super.key,
  });

  final String label;
  final String value;
  final Color? accent;
  final String? caption;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            value,
            style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                  color: accent ?? AppColors.ink50,
                  height: 1.1,
                ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          const SizedBox(height: 6),
          Text(label, style: Theme.of(context).textTheme.labelSmall),
          if (caption != null)
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Text(
                caption!,
                style: Theme.of(context).textTheme.labelSmall?.copyWith(color: AppColors.ink500),
              ),
            ),
        ],
      ),
    );
  }
}

/// Banner used wherever unofficial network geometry is on screen. Required by
/// the product rule that sample data is never presented as the real network.
class SampleDataNotice extends StatelessWidget {
  const SampleDataNotice({this.compact = false, super.key});

  final bool compact;

  @override
  Widget build(BuildContext context) {
    if (compact) {
      return const StatusBadge(
        label: 'داده نمونه',
        colorToken: 'warning',
        icon: Icons.info_outline_rounded,
        compact: true,
      );
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: AppColors.warning.withValues(alpha: 0.10),
        borderRadius: AppRadii.fieldBorder,
        border: Border.all(color: AppColors.warning.withValues(alpha: 0.28)),
      ),
      child: Row(
        children: [
          const Icon(Icons.info_outline_rounded, size: 18, color: AppColors.warning),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'خطوط و ایستگاه‌های این نسخه داده نمونه هستند و مرجع رسمی اتوبوس‌رانی نیستند.',
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: AppColors.warning, height: 1.6),
            ),
          ),
        ],
      ),
    );
  }
}
