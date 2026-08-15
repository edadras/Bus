import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:hamsafar_core/testing.dart';
import 'package:hamsafar_passenger/src/features/plan/plan_screen.dart';
import 'package:hamsafar_passenger/src/providers.dart';

/// The planner's answer is only useful if the itinerary survives the trip to
/// the screen: the lines to take, where to board, where to change, and — when
/// there is no answer — which of the two reasons it was.
void main() {
  Widget wrap(Widget child, {List<Override> overrides = const []}) => ProviderScope(
        overrides: [
          appConfigProvider.overrideWithValue(
            const AppConfig(
              apiBaseUrl: 'http://localhost',
              citySlug: 'bandar-abbas',
              reverbKey: '',
              reverbHost: 'localhost',
              reverbPort: 8080,
              reverbUseTls: false,
              appName: 'همسفر',
              client: 'passenger',
            ),
          ),
          tokenStoreProvider.overrideWithValue(InMemoryTokenStore()),
          ...overrides,
        ],
        child: HamsafarApp(title: 'همسفر', home: child),
      );

  /// Holds a fixed answer and never calls the network, so the screen is what
  /// is under test rather than the transport.
  Override planned(AsyncValue<JourneyPlan>? value) =>
      journeyPlanProvider.overrideWith((ref) => _StubPlanNotifier(ref, value));

  const twoLegPlan = JourneyPlan(
    options: [
      JourneyOption(
        mode: 'bus',
        transfers: 1,
        totalMinutes: 34,
        walkToStopMeters: 240,
        walkFromStopMeters: 180,
        totalWalkMeters: 720,
        legs: [
          JourneyLeg(
            lineCode: '1',
            lineName: 'گلشهر — رسالت',
            boardStopName: 'میدان شهدا',
            alightStopName: 'چهارراه فاطمیه',
            stopsCount: 8,
            rideMinutes: 16,
            headwayMinutes: 12,
          ),
          JourneyLeg(
            lineCode: '4',
            lineName: 'رسالت — بندر',
            boardStopName: 'چهارراه فاطمیه',
            alightStopName: 'اسکله شهید حقانی',
            stopsCount: 6,
            rideMinutes: 9,
          ),
        ],
      ),
    ],
  );

  testWidgets('nothing searched yet is not the same as nothing found', (tester) async {
    await tester.pumpWidget(wrap(const PlanScreen(), overrides: [planned(null)]));
    await tester.pump();

    expect(find.textContaining('مبدأ و مقصد را انتخاب کنید تا'), findsOneWidget);
  });

  testWidgets('an itinerary shows every leg, in order, with where to board', (tester) async {
    await tester.pumpWidget(
      wrap(const PlanScreen(), overrides: [planned(const AsyncValue.data(twoLegPlan))]),
    );
    await tester.pump();

    expect(find.text('گلشهر — رسالت'), findsOneWidget);
    expect(find.text('رسالت — بندر'), findsOneWidget);
    expect(find.textContaining('سوار شوید: میدان شهدا'), findsOneWidget);
    expect(find.textContaining('پیاده شوید: اسکله شهید حقانی'), findsOneWidget);
    // A change is called out explicitly rather than left implied by two legs
    // sitting next to each other.
    expect(find.textContaining('بار تعویض'), findsWidgets);
    // And the walk at each end is placed, not just totalled.
    expect(find.textContaining('متر پیاده تا ایستگاه'), findsOneWidget);
    expect(find.textContaining('متر پیاده تا مقصد'), findsOneWidget);
  });

  testWidgets('an estimate is always labelled as one', (tester) async {
    await tester.pumpWidget(
      wrap(const PlanScreen(), overrides: [planned(const AsyncValue.data(twoLegPlan))]),
    );
    await tester.pump();

    expect(find.textContaining('زمان‌ها تخمینی‌اند'), findsOneWidget);
  });

  // The two empty answers are separate tests on purpose: re-pumping the same
  // ProviderScope reuses its element, so a second set of overrides would never
  // take effect and the test would silently assert on the first plan.
  testWidgets('no stop nearby says so', (tester) async {
    await tester.pumpWidget(
      wrap(
        const PlanScreen(),
        overrides: [
          planned(
            const AsyncValue.data(
              JourneyPlan(options: [], reason: 'no_stop_within_walking_distance'),
            ),
          ),
        ],
      ),
    );
    await tester.pump();

    expect(find.textContaining('ایستگاهی نیست'), findsOneWidget);
  });

  testWidgets('no route found is a different message', (tester) async {
    await tester.pumpWidget(
      wrap(
        const PlanScreen(),
        overrides: [
          planned(const AsyncValue.data(JourneyPlan(options: [], reason: 'no_route_found'))),
        ],
      ),
    );
    await tester.pump();

    expect(find.textContaining('مسیری برای این سفر پیدا نشد'), findsOneWidget);
  });

  testWidgets('the search button stays disabled until both ends are chosen', (tester) async {
    await tester.pumpWidget(
      wrap(
        const PlanScreen(),
        overrides: [
          planned(null),
          // The origin defaults to the device; the destination never does.
          journeyDestinationProvider.overrideWith((ref) => null),
        ],
      ),
    );
    await tester.pump();

    // FilledButton.icon builds a subclass, so byType would miss it.
    final button = tester.widget<ButtonStyleButton>(
      find.byWidgetPredicate((widget) => widget is FilledButton).first,
    );

    expect(button.onPressed, isNull);
    expect(find.textContaining('مبدأ و مقصد را انتخاب کنید.'), findsOneWidget);
  });
}

class _StubPlanNotifier extends JourneyPlanNotifier {
  _StubPlanNotifier(super.ref, AsyncValue<JourneyPlan>? initial) {
    if (initial != null) state = initial;
  }

  @override
  Future<void> search() async {}
}
