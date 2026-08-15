import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

void main() {
  group('ApiException classification', () {
    test('recognises an auth failure', () {
      const error = ApiException(code: 'unauthenticated', message: '', status: 401);

      expect(error.isAuthFailure, isTrue);
    });

    test('recognises the QR states that just need a re-scan', () {
      for (final code in ['qr_expired', 'qr_replayed', 'qr_malformed']) {
        expect(ApiException(code: code, message: '').isStaleQr, isTrue, reason: code);
      }

      // A revoked code is NOT stale: re-scanning will not help, so the UI must
      // not tell the passenger to try again.
      expect(const ApiException(code: 'qr_revoked', message: '').isStaleQr, isFalse);
    });

    test('recognises insufficient funds so the UI can offer a top-up', () {
      const error = ApiException(code: 'insufficient_funds', message: '');

      expect(error.isInsufficientFunds, isTrue);
    });

    test('extracts a per-field validation message', () {
      const error = ApiException(
        code: 'validation_failed',
        message: '',
        status: 422,
        details: {
          'mobile': ['شماره موبایل معتبر نیست.'],
        },
      );

      expect(error.fieldError('mobile'), 'شماره موبایل معتبر نیست.');
      expect(error.fieldError('code'), isNull);
    });

    test('a network failure is distinguishable from a server rejection', () {
      expect(NetworkException().code, 'network_unavailable');
      expect(NetworkException().isAuthFailure, isFalse);
    });
  });
}
