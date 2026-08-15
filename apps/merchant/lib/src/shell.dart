import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import 'features/collect/collect_screen.dart';
import 'features/reports/reports_screen.dart';
import 'features/transactions/transactions_screen.dart';

class MerchantShell extends ConsumerStatefulWidget {
  const MerchantShell({super.key});

  @override
  ConsumerState<MerchantShell> createState() => _MerchantShellState();
}

class _MerchantShellState extends ConsumerState<MerchantShell> {
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
      return const OtpLoginView(
        client: 'merchant',
        title: 'ورود پذیرندگان',
        subtitle: 'با شماره موبایل ثبت‌شده در سامانه وارد شوید',
        icon: Icons.storefront_outlined,
        accent: AppColors.warning,
      );
    }

    return Scaffold(
      backgroundColor: Colors.transparent,
      extendBody: true,
      body: switch (_index) {
        0 => const CollectScreen(),
        1 => const TransactionsScreen(),
        _ => const ReportsScreen(),
      },
      bottomNavigationBar: GlassNavBar(
        currentIndex: _index,
        onTap: (index) => setState(() => _index = index),
        items: const [
          GlassNavItem(
            icon: Icons.qr_code_2_outlined,
            activeIcon: Icons.qr_code_2_rounded,
            label: 'دریافت وجه',
          ),
          GlassNavItem(
            icon: Icons.receipt_long_outlined,
            activeIcon: Icons.receipt_long_rounded,
            label: 'تراکنش‌ها',
          ),
          GlassNavItem(
            icon: Icons.bar_chart_outlined,
            activeIcon: Icons.bar_chart_rounded,
            label: 'گزارش',
          ),
        ],
      ),
    );
  }
}
