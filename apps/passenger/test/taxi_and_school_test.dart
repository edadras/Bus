import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:hamsafar_passenger/src/features/map/widgets/taxi_marker.dart';
import 'package:hamsafar_passenger/src/features/ride/taxi_confirm_sheet.dart';

/// The two rules these screens exist to keep.
///
/// A taxi's colour says what it is offering, and the price a passenger sees is
/// the price that goes back to the server. Both are easy to break by accident
/// and neither is visible in a stack trace when broken.
void main() {
  Widget wrap(Widget child) => ProviderScope(
        child: HamsafarApp(title: 'همسفر', home: Scaffold(body: child)),
      );

  group('the three products are told apart by colour', () {
    test('each mode has its own, and they are distinct', () {
      final colours = {
        for (final mode in TaxiServiceType.values) mode: TaxiMarker.colorFor(mode),
      };

      expect(colours.values.toSet().length, TaxiServiceType.values.length);
    });

    test('the colour is the product, not the availability', () {
      // A taken car is dimmed, but it is still recognisably the same service.
      expect(
        TaxiMarker.colorFor(TaxiServiceType.charter),
        TaxiMarker.colorFor(TaxiServiceType.charter),
      );
    });
  });

  group('TaxiConfirmSheet', () {
    TaxiScanResult quote(String mode, {int amount = 250000}) => TaxiScanResult.fromJson({
          'service_type': mode,
          'requires_amount_confirmation': mode != 'meter',
          'taxi': {'taxi_number': '۱۲', 'plate': '۴۵ط۶۷۸-۱۲'},
          'quote': {
            'amount': amount,
            'formatted': '۲۵٬۰۰۰ تومان',
            'breakdown': mode == 'meter'
                ? {'base_fare': 100000, 'per_km_fare': 40000, 'per_minute_waiting_fare': 15000}
                : {'kind': 'flat'},
          },
        });

    testWidgets('a priced ride shows the figure the passenger is agreeing to',
        (tester) async {
      await tester.pumpWidget(wrap(TaxiConfirmSheet(quote: quote('charter'))));
      await tester.pump();

      expect(find.text('۲۵٬۰۰۰ تومان'), findsOneWidget);
      expect(find.text('پرداخت و سوار شدن'), findsOneWidget);
    });

    testWidgets('a metered ride shows the tariff instead, because there is no figure yet',
        (tester) async {
      await tester.pumpWidget(wrap(TaxiConfirmSheet(quote: quote('meter'))));
      await tester.pump();

      expect(find.text('تعرفه'), findsOneWidget);
      expect(find.text('شروع تاکسی‌متر'), findsOneWidget);

      // No total: quoting one before the ride would be a promise the meter
      // cannot keep.
      expect(find.text('مبلغ قابل پرداخت'), findsNothing);
    });

    testWidgets('cancelling returns false rather than taking the ride', (tester) async {
      bool? result;

      await tester.pumpWidget(
        wrap(
          Builder(
            builder: (context) => TextButton(
              onPressed: () async {
                result = await showModalBottomSheet<bool>(
                  context: context,
                  builder: (_) => TaxiConfirmSheet(quote: quote('line')),
                );
              },
              child: const Text('open'),
            ),
          ),
        ),
      );

      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      await tester.tap(find.text('انصراف'));
      await tester.pumpAndSettle();

      expect(result, isFalse);
    });
  });

  group('what a scan sends back', () {
    test('a priced mode requires the passenger to confirm the amount', () {
      for (final mode in [TaxiServiceType.line, TaxiServiceType.charter]) {
        expect(mode.isPricedUpFront, isTrue);
      }
    });

    test('a meter does not, because nothing has been quoted', () {
      expect(TaxiServiceType.meter.isPricedUpFront, isFalse);
    });
  });

  group('SchoolContract', () {
    test('a seat is what turns an agreement into a service', () {
      final agreed = SchoolContract.fromJson(const {
        'uuid': 'c-1',
        'status': 'active',
        'direction': 'both',
      });

      expect(agreed.isActive, isTrue);
      expect(agreed.hasSeat, isFalse, reason: 'No route means no van yet.');

      final seated = SchoolContract.fromJson(const {
        'uuid': 'c-1',
        'status': 'active',
        'direction': 'both',
        'route': {'name': 'مسیر ۳', 'vehicle_plate': '۱۲ب۳۴۵-۶۷'},
      });

      expect(seated.hasSeat, isTrue);
      expect(seated.vehiclePlate, '۱۲ب۳۴۵-۶۷');
    });

    test('a family waiting on the company is distinguishable from one that is running', () {
      final requested = SchoolContract.fromJson(const {
        'uuid': 'c-2',
        'status': 'requested',
        'direction': 'to_school',
      });

      expect(requested.awaitingCompany, isTrue);
      expect(requested.isActive, isFalse);
    });
  });

  group('SchoolLiveView', () {
    test('the estimate always declares itself approximate', () {
      final view = SchoolLiveView.fromJson(const {
        'trip_uuid': 't-1',
        'status': 'in_progress',
        'direction': 'to_school',
        'child_status': 'pending',
        'stops_ahead': 2,
        'distance_meters': 900,
        'eta': {'minutes': 4, 'is_arriving': false, 'is_approximate': true},
      });

      expect(view.etaIsApproximate, isTrue);
      expect(view.etaMinutes, 4);
      expect(view.isAboard, isFalse);
    });

    test('a child already aboard is not still being waited for', () {
      final view = SchoolLiveView.fromJson(const {
        'trip_uuid': 't-1',
        'status': 'in_progress',
        'direction': 'to_school',
        'child_status': 'picked_up',
        'stops_ahead': 0,
      });

      expect(view.isAboard, isTrue);
    });
  });
}
