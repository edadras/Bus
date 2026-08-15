import 'strings_en.dart';
import 'strings_fa.dart';

/// Translation for the three apps.
///
/// The tables are plain maps keyed exactly as the server's `lang/*` files are
/// (`ride.board_now`, `common.retry`), so a string can move between a Blade
/// view, a JS component and a Flutter screen without being renamed, and one
/// parity test can check them all.
///
/// Deliberately not `flutter gen-l10n`/ARB: that generates a class per locale
/// and a delegate per app, which is three times the ceremony for a two-locale
/// product whose strings are shared across all three apps anyway.
class AppStrings {
  const AppStrings(this.locale);

  /// Persian is the product default, and the fallback when a key is missing.
  static const fallbackLocale = 'fa';

  static const supported = ['fa', 'en'];

  final String locale;

  static const _tables = <String, Map<String, String>>{
    'fa': faStrings,
    'en': enStrings,
  };

  /// Look a key up, substituting `:name` placeholders.
  ///
  /// A key missing from the active locale falls back to Persian rather than
  /// rendering as a dotted key at the user; a key missing from both returns
  /// itself, which is visible in development and never a blank label.
  String call(String key, [Map<String, Object?> params = const {}]) {
    final value = _tables[locale]?[key] ?? _tables[fallbackLocale]?[key] ?? key;

    if (params.isEmpty) return value;

    return params.entries.fold(
      value,
      (text, entry) => text.replaceAll(':${entry.key}', '${entry.value}'),
    );
  }

  bool get isRtl => locale != 'en';

  /// Every key defined in either table, for the parity test.
  static Set<String> keysOf(String locale) => (_tables[locale] ?? const {}).keys.toSet();
}
