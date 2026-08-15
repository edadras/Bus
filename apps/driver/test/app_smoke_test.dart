import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:hamsafar_core/testing.dart';
import 'package:hamsafar_driver/src/shell.dart';

/// The driver app must be closed by default: with no session, the only thing
/// reachable is the sign-in screen.
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
              appName: 'همسفر راننده',
              client: 'driver',
            ),
          ),
          tokenStoreProvider.overrideWithValue(InMemoryTokenStore()),
        ],
        child: HamsafarApp(title: 'همسفر راننده', home: child),
      );

  testWidgets('an unauthenticated driver sees only the sign-in screen',
      (tester) async {
    await tester.pumpWidget(wrap(const DriverShell()));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));

    expect(find.text('ورود رانندگان'), findsOneWidget);
    // No shift controls are reachable before authentication.
    expect(find.byType(GlassNavBar), findsNothing);
  });

  testWidgets('the sign-in form asks for a mobile number first', (tester) async {
    await tester.pumpWidget(wrap(const DriverShell()));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));

    expect(find.text('دریافت کد تأیید'), findsOneWidget);
  });
}
