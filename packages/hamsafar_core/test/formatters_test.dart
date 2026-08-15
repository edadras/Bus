import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

void main() {
  group('Format.money', () {
    test('converts rial minor units to toman for display', () {
      // The API always speaks rial; the UI shows toman. Getting this backwards
      // would show a price ten times too large.
      expect(Format.money(50000, withSuffix: false), '۵٬۰۰۰');
      expect(Format.money(1000000, withSuffix: false), '۱۰۰٬۰۰۰');
    });

    test('renders an em dash for a missing amount rather than zero', () {
      expect(Format.money(null), '—');
    });

    test('appends the unit when asked', () {
      expect(Format.money(50000), contains('تومان'));
    });
  });

  group('Format.normalizeMobile', () {
    test('accepts every form an Iranian number is typed in', () {
      const expected = '989121234567';

      expect(Format.normalizeMobile('09121234567'), expected);
      expect(Format.normalizeMobile('9121234567'), expected);
      expect(Format.normalizeMobile('989121234567'), expected);
      expect(Format.normalizeMobile('00989121234567'), expected);
      expect(Format.normalizeMobile('+98 912 123 4567'), expected);
      expect(Format.normalizeMobile('0912-123-4567'), expected);
    });

    test('converts Persian and Arabic digits', () {
      expect(Format.normalizeMobile('۰۹۱۲۱۲۳۴۵۶۷'), '989121234567');
      expect(Format.normalizeMobile('٠٩١٢١٢٣٤٥٦٧'), '989121234567');
    });

    test('validates the result', () {
      expect(Format.isValidMobile(Format.normalizeMobile('09121234567')), isTrue);
      expect(Format.isValidMobile(Format.normalizeMobile('02112345678')), isFalse);
      expect(Format.isValidMobile(Format.normalizeMobile('0912')), isFalse);
    });
  });

  group('Format.digits', () {
    test('maps Latin digits to Persian and leaves other characters alone', () {
      expect(Format.digits('102'), '۱۰۲');
      expect(Format.digits('خط 102'), 'خط ۱۰۲');
    });

    test('round-trips back to Latin', () {
      expect(Format.toLatinDigits(Format.digits('12345')), '12345');
    });
  });

  group('Format.etaMinutes', () {
    test('never shows zero minutes for an imminent arrival', () {
      // "0 minutes" reads as an error; an arriving bus is "1".
      expect(Format.etaMinutes(20), '۱');
      expect(Format.etaMinutes(59), '۱');
    });

    test('rounds to the nearest minute', () {
      expect(Format.etaMinutes(90), '۲');
      expect(Format.etaMinutes(240), '۴');
    });

    test('handles a missing estimate', () {
      expect(Format.etaMinutes(null), '—');
    });
  });

  group('Format.distance', () {
    test('switches unit at one kilometre', () {
      expect(Format.distance(450), contains('متر'));
      expect(Format.distance(2400), contains('کیلومتر'));
    });
  });
}
