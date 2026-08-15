import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// The Flutter counterpart of the server's locale parity test.
///
/// A declared locale with a missing key does not fall back gracefully in a
/// naive implementation — it renders the key at the user. These tests make
/// that impossible to ship.
void main() {
  group('AppStrings', () {
    test('every key exists in both locales', () {
      final fa = AppStrings.keysOf('fa');
      final en = AppStrings.keysOf('en');

      expect(fa, isNotEmpty);
      expect(fa.difference(en), isEmpty, reason: 'Keys missing from the English table.');
      expect(en.difference(fa), isEmpty, reason: 'Keys missing from the Persian table.');
    });

    test('no key resolves to itself', () {
      for (final locale in AppStrings.supported) {
        final strings = AppStrings(locale);

        for (final key in AppStrings.keysOf(locale)) {
          expect(strings(key), isNot(key), reason: '[$key] is untranslated in [$locale].');
        }
      }
    });

    test('placeholders are substituted', () {
      expect(
        AppStrings('en')('unit.minutes', {'count': 7}),
        '7 min',
      );
    });

    test('an unknown key falls back to Persian rather than rendering the key', () {
      // The English table is the one that can realistically fall behind, so a
      // gap there must degrade to Persian, not to `some.key`.
      expect(AppStrings('en')('unit.toman'), isNot(contains('unit.')));
    });

    test('an entirely unknown key returns itself, visibly', () {
      expect(AppStrings('fa')('nothing.here'), 'nothing.here');
    });
  });

  group('Format follows the active locale', () {
    tearDown(() => Format.locale = 'fa');

    test('money carries the locale unit and numerals', () {
      Format.locale = 'fa';
      expect(Format.money(5000000), '۵۰۰٬۰۰۰ تومان');

      Format.locale = 'en';
      expect(Format.money(5000000), '500,000 Toman');
    });

    test('English keeps Latin digits', () {
      Format.locale = 'en';

      // Persian numerals inside an English sentence are unreadable to whoever
      // asked for English.
      expect(Format.number(1234), '1,234');
      expect(Format.distance(1500), '1.5 km');
      expect(Format.minutes(30), 'less than a minute');
    });

    test('Persian keeps Persian numerals and separator', () {
      Format.locale = 'fa';

      expect(Format.number(1234), '۱٬۲۳۴');
      expect(Format.distance(500), '۵۰۰ متر');
    });
  });
}
