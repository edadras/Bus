import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// The shared design system.
///
/// Mirrors the web design tokens exactly (see resources/css/app.css), so the
/// landing page, the admin panel and the three apps read as one product: a
/// green-led palette on a very dark neutral ground, generous radii, and
/// restrained elevation — on a dark surface, heavy shadows read as dirt, so
/// depth comes from a lit top border instead.
abstract final class AppColors {
  // Brand green. 500 is the interactive default, 400 the "live" accent.
  static const brand50 = Color(0xFFECFDF3);
  static const brand100 = Color(0xFFD1FADF);
  static const brand200 = Color(0xFFA6F4C5);
  static const brand300 = Color(0xFF6CE9A6);
  static const brand400 = Color(0xFF32D583);
  static const brand500 = Color(0xFF12B76A);
  static const brand600 = Color(0xFF039855);
  static const brand700 = Color(0xFF027A48);

  // Near-black neutrals with a faint green cast, never pure grey.
  static const ink950 = Color(0xFF05090B);
  static const ink900 = Color(0xFF0A1114);
  static const ink850 = Color(0xFF0E171B);
  static const ink800 = Color(0xFF131F24);
  static const ink700 = Color(0xFF1B2B31);
  static const ink600 = Color(0xFF2A3F47);
  static const ink500 = Color(0xFF43606A);
  static const ink400 = Color(0xFF6B8892);
  static const ink300 = Color(0xFF9DB2B9);
  static const ink200 = Color(0xFFC6D5D9);
  static const ink100 = Color(0xFFE4ECEE);
  static const ink50 = Color(0xFFF4F8F9);

  static const success = Color(0xFF12B76A);
  static const warning = Color(0xFFF79009);
  static const danger = Color(0xFFF04438);
  static const info = Color(0xFF2E90FA);

  /// Translucent fill for glass surfaces. Must always be paired with
  /// [glassBorder]; without the lit edge it reads muddy rather than glassy.
  static const glassFill = Color(0x14FFFFFF);
  static const glassFillStrong = Color(0x1FFFFFFF);
  static const glassBorder = Color(0x1AFFFFFF);
  static const glassBorderStrong = Color(0x29FFFFFF);

  /// Semantic colour for a status token coming from the API.
  static Color forToken(String? token) => switch (token) {
        'success' => success,
        'warning' => warning,
        'danger' => danger,
        'info' => info,
        _ => ink400,
      };
}

abstract final class AppRadii {
  static const card = 20.0;
  static const field = 14.0;
  static const pill = 999.0;

  static const cardBorder = BorderRadius.all(Radius.circular(card));
  static const fieldBorder = BorderRadius.all(Radius.circular(field));
  static const pillBorder = BorderRadius.all(Radius.circular(pill));
}

abstract final class AppSpacing {
  static const xs = 4.0;
  static const sm = 8.0;
  static const md = 12.0;
  static const lg = 16.0;
  static const xl = 24.0;
  static const xxl = 32.0;
}

abstract final class AppTheme {
  static const fontFamily = 'Vazirmatn';
  static const fontPackage = 'hamsafar_core';

  static ThemeData build() {
    const scheme = ColorScheme.dark(
      primary: AppColors.brand500,
      onPrimary: Colors.white,
      primaryContainer: AppColors.brand700,
      secondary: AppColors.brand400,
      surface: AppColors.ink900,
      onSurface: AppColors.ink100,
      surfaceContainerHighest: AppColors.ink800,
      error: AppColors.danger,
      onError: Colors.white,
      outline: AppColors.ink600,
    );

    final baseTextTheme = _textTheme();

    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.dark,
      colorScheme: scheme,
      scaffoldBackgroundColor: AppColors.ink950,
      fontFamily: fontFamily,
      fontFamilyFallback: const ['Vazirmatn'],
      textTheme: baseTextTheme,
      primaryTextTheme: baseTextTheme,
      splashFactory: InkSparkle.splashFactory,
      appBarTheme: const AppBarTheme(
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        centerTitle: false,
        systemOverlayStyle: SystemUiOverlayStyle(
          statusBarColor: Colors.transparent,
          statusBarIconBrightness: Brightness.light,
          statusBarBrightness: Brightness.dark,
        ),
        titleTextStyle: TextStyle(
          fontFamily: fontFamily,
          package: fontPackage,
          fontSize: 17,
          fontWeight: FontWeight.w700,
          color: AppColors.ink50,
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: AppColors.brand500,
          foregroundColor: Colors.white,
          disabledBackgroundColor: AppColors.ink700,
          disabledForegroundColor: AppColors.ink500,
          // 52 is comfortably above the 48dp minimum: this app is used
          // one-handed, standing on a moving bus.
          minimumSize: const Size.fromHeight(52),
          shape: const RoundedRectangleBorder(borderRadius: AppRadii.pillBorder),
          textStyle: const TextStyle(
            fontFamily: fontFamily,
            package: fontPackage,
            fontSize: 15,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.ink100,
          minimumSize: const Size.fromHeight(52),
          side: const BorderSide(color: AppColors.glassBorderStrong),
          shape: const RoundedRectangleBorder(borderRadius: AppRadii.pillBorder),
          textStyle: const TextStyle(
            fontFamily: fontFamily,
            package: fontPackage,
            fontSize: 15,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: AppColors.brand300,
          textStyle: const TextStyle(
            fontFamily: fontFamily,
            package: fontPackage,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.glassFill,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
        hintStyle: const TextStyle(color: AppColors.ink400, fontSize: 14),
        labelStyle: const TextStyle(color: AppColors.ink300, fontSize: 13),
        border: _fieldBorder(AppColors.glassBorder),
        enabledBorder: _fieldBorder(AppColors.glassBorder),
        focusedBorder: _fieldBorder(AppColors.brand400, width: 1.5),
        errorBorder: _fieldBorder(AppColors.danger),
        focusedErrorBorder: _fieldBorder(AppColors.danger, width: 1.5),
        errorStyle: const TextStyle(color: AppColors.danger, fontSize: 12),
      ),
      cardTheme: const CardThemeData(
        color: AppColors.glassFill,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: AppRadii.cardBorder,
          side: BorderSide(color: AppColors.glassBorder),
        ),
      ),
      dividerTheme: const DividerThemeData(color: Color(0x14FFFFFF), thickness: 1, space: 1),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: AppColors.ink900,
        selectedItemColor: AppColors.brand300,
        unselectedItemColor: AppColors.ink400,
        type: BottomNavigationBarType.fixed,
        elevation: 0,
      ),
      snackBarTheme: const SnackBarThemeData(
        backgroundColor: AppColors.ink800,
        contentTextStyle: TextStyle(
          fontFamily: fontFamily,
          package: fontPackage,
          color: AppColors.ink50,
          fontSize: 14,
        ),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: AppRadii.fieldBorder),
      ),
      dialogTheme: const DialogThemeData(
        backgroundColor: AppColors.ink850,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: AppRadii.cardBorder),
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: AppColors.ink850,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        ),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: AppColors.brand400,
        linearTrackColor: AppColors.ink700,
      ),
      chipTheme: const ChipThemeData(
        backgroundColor: AppColors.glassFill,
        side: BorderSide(color: AppColors.glassBorder),
        labelStyle: TextStyle(
          fontFamily: fontFamily,
          package: fontPackage,
          fontSize: 12,
          color: AppColors.ink200,
        ),
      ),
      listTileTheme: const ListTileThemeData(
        iconColor: AppColors.ink300,
        textColor: AppColors.ink100,
        shape: RoundedRectangleBorder(borderRadius: AppRadii.fieldBorder),
      ),
    );
  }

  static OutlineInputBorder _fieldBorder(Color color, {double width = 1}) => OutlineInputBorder(
        borderRadius: AppRadii.fieldBorder,
        borderSide: BorderSide(color: color, width: width),
      );

  static TextTheme _textTheme() {
    TextStyle style(double size, FontWeight weight, {Color? color, double? height}) => TextStyle(
          fontFamily: fontFamily,
          package: fontPackage,
          fontSize: size,
          fontWeight: weight,
          height: height,
          color: color ?? AppColors.ink100,
        );

    return TextTheme(
      displayLarge: style(34, FontWeight.w700, height: 1.3),
      displayMedium: style(28, FontWeight.w700, height: 1.3),
      headlineMedium: style(22, FontWeight.w700, height: 1.35),
      titleLarge: style(18, FontWeight.w600, height: 1.4),
      titleMedium: style(16, FontWeight.w600, height: 1.5),
      titleSmall: style(14, FontWeight.w600, height: 1.5),
      // Persian needs more line height than Latin to stay readable.
      bodyLarge: style(15, FontWeight.w400, height: 1.8),
      bodyMedium: style(14, FontWeight.w400, height: 1.8, color: AppColors.ink200),
      bodySmall: style(12, FontWeight.w400, height: 1.7, color: AppColors.ink400),
      labelLarge: style(14, FontWeight.w600),
      labelMedium: style(12, FontWeight.w500, color: AppColors.ink300),
      labelSmall: style(11, FontWeight.w500, color: AppColors.ink400),
    );
  }
}

/// The ambient background used behind every screen: two soft green pools over
/// a dark gradient, giving the glass surfaces something to refract.
class AppBackground extends StatelessWidget {
  const AppBackground({required this.child, super.key});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [AppColors.ink950, AppColors.ink900],
        ),
      ),
      child: Stack(
        children: [
          Positioned(
            top: -160,
            right: -100,
            child: _Glow(color: AppColors.brand500.withValues(alpha: 0.18), size: 420),
          ),
          Positioned(
            bottom: -180,
            left: -120,
            child: _Glow(color: AppColors.brand400.withValues(alpha: 0.10), size: 380),
          ),
          child,
        ],
      ),
    );
  }
}

class _Glow extends StatelessWidget {
  const _Glow({required this.color, required this.size});

  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(colors: [color, Colors.transparent]),
        ),
      ),
    );
  }
}
