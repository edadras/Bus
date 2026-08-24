import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import 'features/earnings/earnings_screen.dart';
import 'features/home/taxi_home_screen.dart';
import 'features/rides/rides_screen.dart';

/// Three screens, because a taxi driver's day has three questions: what am I
/// offering right now, who is in the car, and what have I earned.
class TaxiDriverShell extends ConsumerStatefulWidget {
  const TaxiDriverShell({super.key});

  @override
  ConsumerState<TaxiDriverShell> createState() => _TaxiDriverShellState();
}

class _TaxiDriverShellState extends ConsumerState<TaxiDriverShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);

    if (auth.isRestoring) {
      return const AppBackground(
        child: Scaffold(
          backgroundColor: Colors.transparent,
          body: Center(child: CircularProgressIndicator(color: AppColors.warning)),
        ),
      );
    }

    if (!auth.isSignedIn) {
      return OtpLoginView(
        client: 'taxi_driver',
        title: Format.tr('taxi.sign_in_title'),
        subtitle: Format.tr('taxi.sign_in_subtitle'),
        icon: Icons.local_taxi_outlined,
        accent: AppColors.warning,
      );
    }

    return Scaffold(
      backgroundColor: Colors.transparent,
      extendBody: true,
      body: switch (_index) {
        0 => const TaxiHomeScreen(),
        1 => const TaxiRidesScreen(),
        _ => const TaxiEarningsScreen(),
      },
      bottomNavigationBar: GlassNavBar(
        currentIndex: _index,
        onTap: (index) => setState(() => _index = index),
        items: [
          GlassNavItem(
            icon: Icons.local_taxi_outlined,
            activeIcon: Icons.local_taxi_rounded,
            label: Format.tr('taxi.nav_shift'),
          ),
          GlassNavItem(
            icon: Icons.people_outline_rounded,
            activeIcon: Icons.people_rounded,
            label: Format.tr('taxi.nav_rides'),
          ),
          GlassNavItem(
            icon: Icons.payments_outlined,
            activeIcon: Icons.payments_rounded,
            label: Format.tr('taxi.nav_earnings'),
          ),
        ],
      ),
    );
  }
}
