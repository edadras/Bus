import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:hamsafar_core/testing.dart';
import 'package:hamsafar_passenger/src/shell.dart';

/// Smoke tests: build the real widget tree with a stubbed backend.
///
/// The point is not visual coverage but that the app boots, renders RTL, and
/// keeps the public map reachable to a signed-out visitor — the product rule
/// that a passenger can look up a bus before creating an account.
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
          // Signed out, and deterministic: the real store needs a platform
          // keychain that flutter_test does not provide.
          tokenStoreProvider.overrideWithValue(InMemoryTokenStore()),
          ...overrides,
        ],
        child: HamsafarApp(title: 'همسفر', home: child),
      );

  testWidgets('the app renders right-to-left', (tester) async {
    await tester.pumpWidget(wrap(const SizedBox.shrink()));
    await tester.pump();

    final directionality = tester.widget<Directionality>(
      find.byType(Directionality).first,
    );

    expect(directionality.textDirection, TextDirection.rtl);
  });

  testWidgets('a signed-out visitor still reaches the map tab', (tester) async {
    await tester.pumpWidget(wrap(const PassengerShell()));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    // The navigation bar is present and the map tab is the landing tab, with
    // no sign-in wall in front of it.
    expect(find.byType(GlassNavBar), findsOneWidget);
    expect(find.text('نقشه'), findsOneWidget);
    expect(find.text('کیف پول'), findsOneWidget);
  });

  testWidgets('money-touching tabs ask a signed-out visitor to sign in',
      (tester) async {
    await tester.pumpWidget(wrap(const PassengerShell()));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    await tester.tap(find.text('کیف پول'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));

    expect(find.text('ورود لازم است'), findsOneWidget);
  });

  testWidgets('shared widgets render their content', (tester) async {
    await tester.pumpWidget(
      wrap(
        const Scaffold(
          body: Column(
            children: [
              StatusBadge(label: 'فعال', colorToken: 'success'),
              StatTile(label: 'مسافران', value: '۲۷'),
              SampleDataNotice(),
            ],
          ),
        ),
      ),
    );
    await tester.pump();

    expect(find.text('فعال'), findsOneWidget);
    expect(find.text('۲۷'), findsOneWidget);
    // Sample data must always be labelled where it is shown.
    expect(find.textContaining('داده نمونه'), findsOneWidget);
  });
}
