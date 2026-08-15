import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

void main() {
  group('LiveBus', () {
    test('parses a live payload', () {
      final bus = LiveBus.fromJson({
        'trip_uuid': 'abc',
        'lat': 27.18,
        'lng': 56.26,
        'bus_number': 102,
        'line_code': '102',
        'passenger_count': 27,
        'occupancy': 0.54,
        'heading': 91.5,
        'updated_at': DateTime.now().toIso8601String(),
      });

      expect(bus.tripUuid, 'abc');
      expect(bus.passengerCount, 27);
      // Numeric fields arrive as ints or strings depending on the driver app.
      expect(bus.busNumber, '102');
      expect(bus.isStale, isFalse);
    });

    test('marks a bus stale once its last ping is old', () {
      final bus = LiveBus.fromJson({
        'trip_uuid': 'abc',
        'lat': 27.18,
        'lng': 56.26,
        'updated_at': DateTime.now().subtract(const Duration(minutes: 5)).toIso8601String(),
      });

      expect(bus.isStale, isTrue);
    });

    test('treats a missing timestamp as stale rather than fresh', () {
      final bus = LiveBus.fromJson({'trip_uuid': 'abc', 'lat': 27.0, 'lng': 56.0});

      expect(bus.isStale, isTrue);
    });
  });

  group('Arrival', () {
    test('surfaces the server-supplied reliability flag', () {
      final reliable = Arrival.fromJson({
        'trip_uuid': 'a',
        'eta': {'seconds': 240, 'minutes': 4, 'confidence': 0.82, 'reliable': true},
      });

      final unreliable = Arrival.fromJson({
        'trip_uuid': 'b',
        'eta': {'seconds': 900, 'minutes': 15, 'confidence': 0.3, 'reliable': false},
      });

      expect(reliable.isReliable, isTrue);
      expect(unreliable.isReliable, isFalse);
    });

    test('survives a malformed eta block', () {
      final arrival = Arrival.fromJson({'trip_uuid': 'a'});

      expect(arrival.etaSeconds, 0);
      expect(arrival.isReliable, isFalse);
    });
  });

  group('BusStop', () {
    test('reports unverified provenance so the UI can label it', () {
      final sample = BusStop.fromJson({
        'id': 1, 'code': 'A', 'name': 'ایستگاه',
        'lat': 27.1, 'lng': 56.2, 'is_verified_data': false,
      });

      expect(sample.isVerifiedData, isFalse);
    });
  });

  group('Wallet', () {
    test('parses a balance and spendability', () {
      final wallet = Wallet.fromJson({
        'balance': 500000,
        'formatted_balance': '۵۰٬۰۰۰ تومان',
        'status_label': 'فعال',
        'can_spend': true,
      });

      expect(wallet.balance, 500000);
      expect(wallet.canSpend, isTrue);
    });

    test('a frozen wallet cannot spend', () {
      final wallet = Wallet.fromJson({
        'balance': 500000, 'status_label': 'مسدود', 'can_spend': false,
      });

      expect(wallet.canSpend, isFalse);
    });
  });

  group('MapConfig', () {
    test('falls back to a usable provider when the payload is empty', () {
      final config = MapConfig.fromJson(const {});

      expect(config.tileUrl, isNotEmpty);
      expect(config.center.lat, isNot(0));
    });
  });
}
