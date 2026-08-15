import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// The merchant, the signed-in staff member's permissions, the wallet and the
/// terminals available to display a payment code.
final merchantStateProvider = FutureProvider<MerchantState>((ref) async {
  final data = await ref.watch(transitApiProvider).merchantState();

  return MerchantState.fromJson(data);
});

class MerchantState {
  const MerchantState({
    required this.name,
    required this.code,
    required this.typeLabel,
    required this.status,
    required this.balance,
    required this.formattedBalance,
    required this.canRefund,
    required this.canViewReports,
    required this.isManager,
    required this.commissionBps,
    required this.terminals,
    required this.pendingSettlement,
  });

  final String name;
  final String code;
  final String typeLabel;
  final String status;
  final int balance;
  final String formattedBalance;

  /// Refunds and reports are per-staff-member permissions from the server, not
  /// a client-side toggle: a cashier's build is identical to a manager's.
  final bool canRefund;
  final bool canViewReports;
  final bool isManager;

  final int commissionBps;
  final List<MerchantTerminal> terminals;
  final PendingSettlement pendingSettlement;

  bool get isActive => status == 'active';

  factory MerchantState.fromJson(Map<String, dynamic> json) {
    final merchant = (json['merchant'] as Map<String, dynamic>?) ?? const {};
    final staff = (json['staff'] as Map<String, dynamic>?) ?? const {};
    final wallet = (json['wallet'] as Map<String, dynamic>?) ?? const {};

    return MerchantState(
      name: merchant['name'] as String? ?? '—',
      code: merchant['code'] as String? ?? '',
      typeLabel: merchant['type_label'] as String? ?? '',
      status: merchant['status'] as String? ?? 'pending_approval',
      balance: (wallet['balance'] as num?)?.toInt() ?? 0,
      formattedBalance: wallet['formatted_balance'] as String? ?? '—',
      canRefund: staff['can_refund'] == true,
      canViewReports: staff['can_view_reports'] == true || staff['role'] == 'manager',
      isManager: staff['role'] == 'manager',
      commissionBps: (merchant['commission_bps'] as num?)?.toInt() ?? 0,
      terminals: ((json['terminals'] as List<dynamic>?) ?? const [])
          .cast<Map<String, dynamic>>()
          .map(MerchantTerminal.fromJson)
          .toList(),
      pendingSettlement: PendingSettlement.fromJson(
        (json['pending_settlement'] as Map<String, dynamic>?) ?? const {},
      ),
    );
  }
}

class MerchantTerminal {
  const MerchantTerminal({
    required this.id,
    required this.name,
    required this.publicId,
    this.location,
  });

  final int id;
  final String name;
  final String publicId;
  final String? location;

  factory MerchantTerminal.fromJson(Map<String, dynamic> json) => MerchantTerminal(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: json['name'] as String? ?? '—',
        publicId: json['public_id'] as String? ?? '',
        location: json['location'] as String?,
      );
}

class PendingSettlement {
  const PendingSettlement({
    required this.transactionCount,
    required this.grossAmount,
    required this.commissionAmount,
    required this.netAmount,
  });

  final int transactionCount;
  final int grossAmount;
  final int commissionAmount;
  final int netAmount;

  factory PendingSettlement.fromJson(Map<String, dynamic> json) => PendingSettlement(
        transactionCount: (json['transaction_count'] as num?)?.toInt() ?? 0,
        grossAmount: (json['gross_amount'] as num?)?.toInt() ?? 0,
        commissionAmount: (json['commission_amount'] as num?)?.toInt() ?? 0,
        netAmount: (json['net_amount'] as num?)?.toInt() ?? 0,
      );
}

/// The rotating token shown on the till.
///
/// Refreshed a little before it actually expires, so the customer's camera
/// never catches the moment between one code dying and the next appearing.
class TerminalTokenNotifier extends StateNotifier<AsyncValue<TerminalToken>> {
  TerminalTokenNotifier(this._ref, this._terminalId) : super(const AsyncValue.loading()) {
    unawaited(refresh());
  }

  final Ref _ref;
  final int _terminalId;
  Timer? _timer;

  Future<void> refresh() async {
    try {
      final data = await _ref.read(transitApiProvider).terminalToken(_terminalId);

      if (!mounted) return;

      final token = TerminalToken.fromJson(data);
      state = AsyncValue.data(token);

      _timer?.cancel();
      _timer = Timer(
        Duration(seconds: (token.expiresIn - 2).clamp(2, 60)),
        refresh,
      );
    } catch (error, stack) {
      if (mounted) state = AsyncValue.error(error, stack);
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}

class TerminalToken {
  const TerminalToken({
    required this.token,
    required this.publicId,
    required this.expiresIn,
    required this.rotationSeconds,
  });

  final String token;
  final String publicId;
  final int expiresIn;
  final int rotationSeconds;

  factory TerminalToken.fromJson(Map<String, dynamic> json) => TerminalToken(
        token: json['token'] as String? ?? '',
        publicId: json['public_id'] as String? ?? '',
        expiresIn: (json['expires_in'] as num?)?.toInt() ?? 30,
        rotationSeconds: (json['rotation_seconds'] as num?)?.toInt() ?? 30,
      );
}

final terminalTokenProvider =
    StateNotifierProvider.autoDispose.family<TerminalTokenNotifier, AsyncValue<TerminalToken>, int>(
  TerminalTokenNotifier.new,
);

final merchantTransactionsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>(
  (ref) => ref.watch(transitApiProvider).merchantTransactions(),
);

final salesReportProvider = FutureProvider.autoDispose<Map<String, dynamic>>(
  (ref) => ref.watch(transitApiProvider).merchantSalesReport(),
);
