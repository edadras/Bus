import 'package:intl/intl.dart';

import '../l10n/app_strings.dart';

/// Presentation helpers.
///
/// The product is Persian-first, so numbers are rendered with Persian digits
/// and money is displayed in Toman while the API always speaks in Rial minor
/// units. Doing that conversion in exactly one place is what keeps a
/// ten-times-too-large price off the screen.
///
/// The active locale is a static rather than a parameter. These are called from
/// build methods all over three apps — hundreds of call sites — and threading a
/// locale through every one would buy nothing: there is exactly one active
/// locale per process, and `LocaleController` keeps this in step with it.
abstract final class Format {
  /// Kept in step with `localeProvider` by each app's root widget.
  static String locale = AppStrings.fallbackLocale;

  static AppStrings get _t => AppStrings(locale);

  static bool get _isPersian => locale != 'en';

  static const _persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

  /// Display unit for money; matches config('wallet.display_unit') server-side.
  static const displayUnit = 'toman';

  /// Latin digits mapped to the locale's numerals.
  ///
  /// English is left alone: Persian numerals inside an English sentence are
  /// unreadable to the person who asked for English.
  static String digits(String input) {
    if (!_isPersian) return input;

    final buffer = StringBuffer();

    for (final rune in input.runes) {
      final char = String.fromCharCode(rune);
      final index = int.tryParse(char);
      buffer.write(index == null ? char : _persianDigits[index]);
    }

    return buffer.toString();
  }

  /// Latin digits back out of Persian/Arabic input, for anything the user types.
  static String toLatinDigits(String input) {
    const persian = '۰۱۲۳۴۵۶۷۸۹';
    const arabic = '٠١٢٣٤٥٦٧٨٩';

    final buffer = StringBuffer();

    for (final rune in input.runes) {
      final char = String.fromCharCode(rune);
      final persianIndex = persian.indexOf(char);
      final arabicIndex = arabic.indexOf(char);

      if (persianIndex >= 0) {
        buffer.write(persianIndex);
      } else if (arabicIndex >= 0) {
        buffer.write(arabicIndex);
      } else {
        buffer.write(char);
      }
    }

    return buffer.toString();
  }

  /// Persian thousands separator (U+066C). Leaving the Latin comma in place
  /// looks wrong beside Persian digits, which is the whole reason this
  /// formatter exists rather than calling NumberFormat directly.
  static const _thousandsSeparator = '٬';

  static String number(num? value) {
    final formatted = NumberFormat.decimalPattern('en').format(value ?? 0);

    return _isPersian ? digits(formatted.replaceAll(',', _thousandsSeparator)) : formatted;
  }

  /// Minor units (rial) formatted for display, in Toman by default.
  static String money(int? minorUnits, {bool withSuffix = true}) {
    if (minorUnits == null) return '—';

    final value = displayUnit == 'toman' ? minorUnits ~/ 10 : minorUnits;
    final formatted = number(value);

    return withSuffix ? '$formatted ${_t('unit.$displayUnit')}' : formatted;
  }

  static String minutes(int? seconds) {
    if (seconds == null) return '—';
    if (seconds < 60) return _t('unit.under_a_minute');

    return _t('unit.minutes', {'count': number((seconds / 60).round())});
  }

  /// Compact form for the ETA badge, where only the number fits.
  static String etaMinutes(int? seconds) =>
      seconds == null ? '—' : number(seconds < 60 ? 1 : (seconds / 60).round());

  static String distance(num? meters) {
    if (meters == null) return '—';

    return meters < 1000
        ? _t('unit.metres', {'count': number(meters.round())})
        : _t('unit.kilometres', {'count': digits((meters / 1000).toStringAsFixed(1))});
  }

  static String time(DateTime? at) =>
      at == null ? '—' : digits(DateFormat('HH:mm').format(at.toLocal()));

  static String dateTime(DateTime? at) =>
      at == null ? '—' : digits(DateFormat('yyyy/MM/dd — HH:mm').format(at.toLocal()));

  static String relative(DateTime? at) {
    if (at == null) return '—';

    final difference = DateTime.now().difference(at);

    if (difference.inSeconds < 60) return _t('unit.moments_ago');
    if (difference.inMinutes < 60) {
      return _t('unit.minutes_ago', {'count': number(difference.inMinutes)});
    }
    if (difference.inHours < 24) return _t('unit.hours_ago', {'count': number(difference.inHours)});
    if (difference.inDays < 30) return _t('unit.days_ago', {'count': number(difference.inDays)});

    return dateTime(at);
  }

  static String duration(int? seconds) {
    if (seconds == null) return '—';

    final hours = seconds ~/ 3600;
    final mins = (seconds % 3600) ~/ 60;

    if (hours > 0) {
      return _t('unit.hours_and_minutes', {'hours': number(hours), 'minutes': number(mins)});
    }

    return _t('unit.minutes', {'count': number(mins)});
  }

  /// Normalise a typed mobile number to the canonical 98XXXXXXXXXX form,
  /// tolerating Persian digits, spaces and the various prefixes people use.
  static String normalizeMobile(String input) {
    final digitsOnly = toLatinDigits(input).replaceAll(RegExp(r'\D'), '');

    if (digitsOnly.startsWith('0098')) return '98${digitsOnly.substring(4)}';
    if (digitsOnly.startsWith('98') && digitsOnly.length == 12) return digitsOnly;
    if (digitsOnly.startsWith('0')) return '98${digitsOnly.substring(1)}';
    if (digitsOnly.length == 10 && digitsOnly.startsWith('9')) return '98$digitsOnly';

    return digitsOnly;
  }

  static bool isValidMobile(String normalized) => RegExp(r'^989\d{9}$').hasMatch(normalized);

  /// Screens call this rather than reaching for the table directly, so a string
  /// that sits beside a formatted number stays in one place.
  static String tr(String key, [Map<String, Object?> params = const {}]) => _t(key, params);
}
