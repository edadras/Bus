import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:hamsafar_core/testing.dart';
import 'package:hamsafar_school/src/shell.dart';

/// The manifest carries children's names, addresses and medical notes. Nothing
/// on this app may be reachable without a session.
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
              appName: 'همسفر سرویس مدارس',
              client: 'school_driver',
            ),
          ),
          tokenStoreProvider.overrideWithValue(InMemoryTokenStore()),
        ],
        child: HamsafarApp(title: 'همسفر سرویس مدارس', home: child),
      );

  testWidgets('an unauthenticated driver sees only the sign-in screen', (tester) async {
    await tester.pumpWidget(wrap(const SchoolDriverShell()));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));

    expect(find.text('ورود رانندگان سرویس'), findsOneWidget);
  });

  group('SchoolTripStudent', () {
    test('a child is settled once their journey has ended, however it ended', () {
      for (final status in ['dropped_off', 'absent', 'no_show']) {
        final row = SchoolTripStudent.fromJson({'uuid': 's', 'status': status});

        expect(row.isSettled, isTrue, reason: '[$status] should count as finished.');
      }

      for (final status in ['pending', 'picked_up']) {
        final row = SchoolTripStudent.fromJson({'uuid': 's', 'status': status});

        expect(row.isSettled, isFalse, reason: '[$status] is still in progress.');
      }
    });

    test('the manifest reads the emergency details the driver may need', () {
      final row = SchoolTripStudent.fromJson(const {
        'uuid': 's-1',
        'sequence': 3,
        'status': 'pending',
        'pickup_address': 'خیابان طالقانی، کوچه ۴',
        'student': {
          'name': 'سارا رضایی',
          'medical_notes': 'آسم',
          'emergency_contact_phone': '09121234567',
          'guardian_phone': '09129876543',
        },
      });

      expect(row.name, 'سارا رضایی');
      expect(row.medicalNotes, 'آسم');
      expect(row.guardianPhone, '09129876543');
      expect(row.emergencyContactPhone, '09121234567');
    });
  });

  group('SchoolTrip', () {
    test('a run is settled only when nobody is still waiting to be checked', () {
      SchoolTrip build(List<String> statuses) => SchoolTrip.fromJson({
            'uuid': 't-1',
            'direction': 'to_school',
            'status': 'in_progress',
            'students': [
              for (final status in statuses) {'uuid': status, 'status': status},
            ],
          });

      expect(build(['dropped_off', 'absent']).isSettled, isTrue);
      expect(build(['dropped_off', 'pending']).isSettled, isFalse);
      expect(build(['picked_up']).isSettled, isFalse);
    });
  });
}
