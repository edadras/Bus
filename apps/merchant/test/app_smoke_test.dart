import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:hamsafar_core/testing.dart';
import 'package:hamsafar_merchant/src/shell.dart';

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
              appName: 'همسفر پذیرنده',
              client: 'merchant',
            ),
          ),
          tokenStoreProvider.overrideWithValue(InMemoryTokenStore()),
        ],
        child: HamsafarApp(title: 'همسفر پذیرنده', home: child),
      );

  testWidgets('an unauthenticated merchant sees only the sign-in screen',
      (tester) async {
    await tester.pumpWidget(wrap(const MerchantShell()));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));

    expect(find.text('ورود پذیرندگان'), findsOneWidget);
    expect(find.byType(GlassNavBar), findsNothing);
  });
}
