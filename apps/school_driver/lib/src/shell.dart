import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import 'features/runs/runs_screen.dart';

/// One screen, because the app has one job.
///
/// The manifest is the app: today's runs, and inside each one a list of
/// children in the order they are collected. Anything else would be a second
/// thing to look at while driving.
class SchoolDriverShell extends ConsumerWidget {
  const SchoolDriverShell({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final auth = ref.watch(authControllerProvider);

    if (auth.isRestoring) {
      return const AppBackground(
        child: Scaffold(
          backgroundColor: Colors.transparent,
          body: Center(child: CircularProgressIndicator(color: AppColors.brand400)),
        ),
      );
    }

    if (!auth.isSignedIn) {
      return OtpLoginView(
        client: 'school_driver',
        title: Format.tr('school.sign_in_title'),
        subtitle: Format.tr('school.sign_in_subtitle'),
        icon: Icons.airport_shuttle_outlined,
        accent: AppColors.brand500,
      );
    }

    return const SchoolRunsScreen();
  }
}
