import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import 'features/home/driver_home_screen.dart';
import 'features/passengers/passengers_screen.dart';
import 'features/route/route_screen.dart';

/// The driver app is gated end to end: nothing is reachable without an
/// approved driver record, because the token that authorises a shift is only
/// ever minted for one.
class DriverShell extends ConsumerStatefulWidget {
  const DriverShell({super.key});

  @override
  ConsumerState<DriverShell> createState() => _DriverShellState();
}

class _DriverShellState extends ConsumerState<DriverShell> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);

    if (auth.isRestoring) {
      return const AppBackground(
        child: Scaffold(
          backgroundColor: Colors.transparent,
          body: Center(child: CircularProgressIndicator(color: AppColors.info)),
        ),
      );
    }

    if (!auth.isSignedIn) {
      return const OtpLoginView(
        client: 'driver',
        title: 'ورود رانندگان',
        subtitle: 'فقط رانندگان تأییدشده می‌توانند وارد شوند',
        icon: Icons.badge_outlined,
        accent: AppColors.info,
      );
    }

    return Scaffold(
      backgroundColor: Colors.transparent,
      extendBody: true,
      body: switch (_index) {
        0 => const DriverHomeScreen(),
        1 => const RouteScreen(),
        _ => const PassengersScreen(),
      },
      bottomNavigationBar: GlassNavBar(
        currentIndex: _index,
        onTap: (index) => setState(() => _index = index),
        items: const [
          GlassNavItem(
            icon: Icons.dashboard_outlined,
            activeIcon: Icons.dashboard_rounded,
            label: 'شیفت',
          ),
          GlassNavItem(
            icon: Icons.route_outlined,
            activeIcon: Icons.route_rounded,
            label: 'مسیر',
          ),
          GlassNavItem(
            icon: Icons.people_outline_rounded,
            activeIcon: Icons.people_rounded,
            label: 'مسافران',
          ),
        ],
      ),
    );
  }
}
