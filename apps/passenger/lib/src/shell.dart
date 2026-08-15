import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import 'features/account/account_screen.dart';
import 'features/complaints/complaints_screen.dart';
import 'features/map/map_screen.dart';
import 'features/ride/ride_screen.dart';
import 'features/wallet/wallet_screen.dart';

/// Tab shell for the passenger app.
///
/// Note the map tab is available before signing in: a passenger must be able
/// to look up a stop and see the next bus without an account. Only the tabs
/// that move money or touch personal data require authentication.
class PassengerShell extends ConsumerStatefulWidget {
  const PassengerShell({super.key});

  @override
  ConsumerState<PassengerShell> createState() => _PassengerShellState();
}

class _PassengerShellState extends ConsumerState<PassengerShell> {
  int _index = 0;

  static const _requiresAuth = {1, 2, 3};

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);

    if (auth.isRestoring) {
      return const AppBackground(
        child: Scaffold(
          backgroundColor: Colors.transparent,
          body: Center(child: CircularProgressIndicator(color: AppColors.brand400)),
        ),
      );
    }

    // Guard rather than hide: the tab stays visible so the user can discover
    // the feature, and tapping it explains what sign-in unlocks.
    final needsSignIn = _requiresAuth.contains(_index) && !auth.isSignedIn;

    final screen = needsSignIn
        ? const _SignInPrompt()
        : switch (_index) {
            0 => const MapScreen(),
            1 => const RideScreen(),
            2 => const WalletScreen(),
            3 => const ComplaintsScreen(),
            _ => const AccountScreen(),
          };

    return Scaffold(
      backgroundColor: Colors.transparent,
      extendBody: true,
      body: screen,
      bottomNavigationBar: GlassNavBar(
        currentIndex: _index,
        onTap: (index) => setState(() => _index = index),
        items: [
          GlassNavItem(
              icon: Icons.map_outlined, activeIcon: Icons.map_rounded, label: Format.tr('nav.map')),
          GlassNavItem(
            icon: Icons.qr_code_scanner_outlined,
            activeIcon: Icons.qr_code_scanner_rounded,
            label: Format.tr('nav.ride'),
          ),
          GlassNavItem(
            icon: Icons.account_balance_wallet_outlined,
            activeIcon: Icons.account_balance_wallet_rounded,
            label: Format.tr('nav.wallet'),
          ),
          GlassNavItem(
            icon: Icons.support_agent_outlined,
            activeIcon: Icons.support_agent_rounded,
            label: Format.tr('nav.support'),
          ),
          GlassNavItem(
            icon: Icons.person_outline_rounded,
            activeIcon: Icons.person_rounded,
            label: Format.tr('nav.account'),
          ),
        ],
      ),
    );
  }
}

class _SignInPrompt extends ConsumerWidget {
  const _SignInPrompt();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return AppScaffold(
      title: Format.tr('gate.title'),
      body: Center(
        child: SingleChildScrollView(
          child: GlassCard(
            strong: true,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.lock_outline_rounded, size: 36, color: AppColors.ink400),
                const SizedBox(height: AppSpacing.lg),
                Text(
                  Format.tr('gate.body'),
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  Format.tr('gate.public_note'),
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.bodySmall,
                ),
                const SizedBox(height: AppSpacing.xl),
                FilledButton(
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(
                      builder: (_) => OtpLoginView(
                        client: 'passenger',
                        title: Format.tr('app.sign_in_title'),
                        subtitle: Format.tr('app.sign_in_subtitle'),
                      ),
                    ),
                  ),
                  child: Text(Format.tr('app.sign_in_with_mobile')),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
