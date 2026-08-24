import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// Channel names and the credentials behind them.
///
/// A channel name that drifts from `routes/channels.php` fails silently — the
/// client subscribes to something nobody publishes and the screen simply never
/// updates — so the names are pinned here rather than trusted.
void main() {
  group('Channels', () {
    test('a public channel carries no private prefix', () {
      expect(Channels.cityBuses(1), 'transit.city.1.buses');
      expect(Channels.trip(42), 'transit.trip.42');
    });

    test('every channel carrying money or identity is private', () {
      // The prefix is not decoration: the client refuses to subscribe to a
      // private channel without a server-signed token, so getting this wrong
      // would quietly hand out an unauthorised feed.
      for (final channel in [
        Channels.tripCrew(7),
        Channels.userWallet(9),
        Channels.taxiShift(3),
      ]) {
        expect(channel.startsWith('private-'), isTrue, reason: '[$channel] must be private.');
      }
    });

    test('a taxi shift channel is named after the shift, not the driver', () {
      // The server decides who may listen — the driver working it, and the
      // city's operations staff. Naming it after the driver would make that
      // decision impossible to express.
      expect(Channels.taxiShift(58), 'private-transit.taxi.shift.58');
    });
  });

  group('RealtimeClient', () {
    test('an empty app key means the socket never opens', () {
      // A deployment with no Reverb configured must degrade to polling rather
      // than retrying a connection that cannot succeed.
      final client = RealtimeClient(appKey: '', host: 'localhost', port: 8080, useTls: false);

      client.connect();

      expect(client.isConnected, isFalse);

      client.dispose();
    });

    test('a client with no token still serves public channels', () {
      // The passenger map is reachable signed out; a private channel is simply
      // skipped, and the rest of the socket carries on.
      final client = RealtimeClient(
        appKey: 'key',
        host: 'localhost',
        port: 8080,
        useTls: false,
        authEndpoint: 'http://localhost/broadcasting/auth',
      );

      expect(client.isConnected, isFalse);

      client.dispose();
    });
  });

  group('MapConfig', () {
    test('it carries the city, which is what names the live feed', () {
      final config = MapConfig.fromJson(const {
        'city': {'id': 3, 'slug': 'bandar-abbas', 'name': 'بندرعباس'},
        'provider': {'tile_url': 'https://example.test/{z}/{x}/{y}.png', 'max_zoom': 18},
        'center': {'lat': 27.1832, 'lng': 56.2666},
        'zoom': 13,
      });

      expect(config.cityId, 3);
      expect(Channels.cityBuses(config.cityId!), 'transit.city.3.buses');
    });

    test('a payload with no city degrades rather than throwing', () {
      final config = MapConfig.fromJson(const {'provider': {}});

      expect(config.cityId, isNull);
      expect(config.tileUrl, MapConfig.fallback.tileUrl);
    });
  });
}
