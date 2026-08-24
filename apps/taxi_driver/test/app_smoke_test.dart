import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:hamsafar_core/testing.dart';
import 'package:hamsafar_taxi/src/shell.dart';

/// The taxi driver app must be closed by default: with no session, the only
/// thing reachable is the sign-in screen. A fare code on an unauthenticated
/// screen would be a code nobody vouched for.
void main() {
  Widget wrap(Widget child) => ProviderScope(
        overrides: [
          appConfigProvider.overrideWithValue(
            const AppConfig(
              apiBaseUrl: 'http://localhost',
              citySlug: 'bandar-abbas',
              reverbKey: '',
              reverbHost: 'localhost',
              reverbPort: 8080,
              reverbUseTls: false,
              appName: 'همسفر تاکسی',
              client: 'taxi_driver',
            ),
          ),
          tokenStoreProvider.overrideWithValue(InMemoryTokenStore()),
        ],
        child: HamsafarApp(title: 'همسفر تاکسی', home: child),
      );

  testWidgets('an unauthenticated driver sees only the sign-in screen', (tester) async {
    await tester.pumpWidget(wrap(const TaxiDriverShell()));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));

    expect(find.text('ورود رانندگان تاکسی'), findsOneWidget);
    expect(find.byType(GlassNavBar), findsNothing);
  });

  group('TaxiServiceType', () {
    test('an unknown mode falls back to the shared line rather than crashing', () {
      expect(TaxiServiceType.from('nonsense'), TaxiServiceType.line);
      expect(TaxiServiceType.from(null), TaxiServiceType.line);
    });

    test('only the meter is open ended, and only it is unpriced up front', () {
      expect(TaxiServiceType.meter.isOpenEnded, isTrue);
      expect(TaxiServiceType.meter.isPricedUpFront, isFalse);

      for (final mode in [TaxiServiceType.line, TaxiServiceType.charter]) {
        expect(mode.isOpenEnded, isFalse);
        expect(mode.isPricedUpFront, isTrue);
      }
    });
  });

  group('TaxiRide', () {
    test('an unpaid ride carries the debt, not a fare', () {
      // The distinction the whole earnings report rests on: money that moved
      // versus money still owed.
      final ride = TaxiRide.fromJson(const {
        'uuid': 'r-1',
        'service_type': 'meter',
        'status': 'unpaid',
        'fare_amount': 0,
        'outstanding_amount': 145000,
      });

      expect(ride.isUnpaid, isTrue);
      expect(ride.fareAmount, 0);
      expect(ride.outstandingAmount, 145000);
    });

    test('a running meter reads its total from the live quote', () {
      final ride = TaxiRide.fromJson(const {
        'uuid': 'r-2',
        'service_type': 'meter',
        'status': 'active',
        'is_open': true,
        'fare_amount': 0,
        'current_fare': {
          'amount': 82000,
          'formatted': '۸٬۲۰۰ تومان',
          'breakdown': {'distance_meters': 3200, 'waiting_seconds': 90},
        },
      });

      expect(ride.currentFare?.amount, 82000);
      expect(ride.currentFare?.distanceMeters, 3200);
      expect(ride.currentFare?.waitingSeconds, 90);
    });
  });
}
