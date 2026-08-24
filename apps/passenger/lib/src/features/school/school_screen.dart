import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';
import 'contract_request_sheet.dart';
import 'invoices_screen.dart';
import 'live_tracking_screen.dart';
import 'student_form_sheet.dart';

/// A parent's school service: their children, the arrangement for each, and
/// the way to watch the van when it is on the road.
///
/// Contracts are per child rather than per family, because two children at two
/// schools are two arrangements with two companies and two fees.
class SchoolScreen extends ConsumerWidget {
  const SchoolScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final students = ref.watch(schoolStudentsProvider);
    final contracts = ref.watch(schoolContractsProvider);
    final invoices = ref.watch(schoolInvoicesProvider).valueOrNull ?? const <SchoolInvoice>[];
    final theme = Theme.of(context);

    var payable = 0;

    for (final invoice in invoices) {
      if (invoice.isPayable) payable += invoice.amount;
    }

    return AppScaffold(
      title: Format.tr('school_service.title'),
      leading: const BackButton(),
      actions: [
        IconButton(
          tooltip: Format.tr('school_service.invoices'),
          onPressed: () => Navigator.of(context).push(
            MaterialPageRoute<void>(builder: (_) => const SchoolInvoicesScreen()),
          ),
          icon: const Icon(Icons.receipt_long_outlined, size: 20),
        ),
      ],
      onRefresh: () async {
        ref
          ..invalidate(schoolStudentsProvider)
          ..invalidate(schoolContractsProvider)
          ..invalidate(schoolInvoicesProvider);
        await ref.read(schoolContractsProvider.future);
      },
      body: students.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.brand400)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('school_service.load_failed'),
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(schoolStudentsProvider),
        ),
        data: (children) => ListView(
          padding: const EdgeInsets.only(bottom: 110),
          children: [
            if (payable > 0) ...[
              GlassCard(
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(builder: (_) => const SchoolInvoicesScreen()),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.receipt_long_rounded, size: 20, color: AppColors.warning),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        Format.tr('school_service.payable_total', {
                          'amount': Format.money(payable),
                        }),
                        style: theme.textTheme.titleSmall,
                      ),
                    ),
                    const Icon(Icons.chevron_left_rounded, size: 20, color: AppColors.ink400),
                  ],
                ),
              ),
              const SizedBox(height: AppSpacing.lg),
            ],

            if (children.isEmpty)
              EmptyState(
                icon: Icons.child_care_rounded,
                message: Format.tr('school_service.no_children'),
                actionLabel: Format.tr('school_service.add_child'),
                onAction: () => showStudentForm(context, ref),
              )
            else ...[
              for (final child in children) ...[
                _ChildCard(
                  student: child,
                  contracts: contracts.valueOrNull ?? const [],
                ),
                const SizedBox(height: AppSpacing.md),
              ],
              OutlinedButton.icon(
                onPressed: () => showStudentForm(context, ref),
                icon: const Icon(Icons.add_rounded, size: 18),
                label: Text(Format.tr('school_service.add_child')),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _ChildCard extends ConsumerWidget {
  const _ChildCard({required this.student, required this.contracts});

  final SchoolStudent student;
  final List<SchoolContract> contracts;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);

    SchoolContract? contract;

    for (final candidate in contracts) {
      if (candidate.studentUuid == student.uuid) {
        contract = candidate;
        break;
      }
    }

    return GlassCard(
      strong: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Container(
                width: 42,
                height: 42,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: AppColors.brand500.withValues(alpha: 0.14),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: const Icon(Icons.child_care_rounded, color: AppColors.brand300, size: 20),
              ),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(student.name, style: theme.textTheme.titleMedium),
                    Text(
                      [student.grade, student.schoolName].whereType<String>().join(' · '),
                      style: theme.textTheme.labelSmall,
                    ),
                  ],
                ),
              ),
              IconButton(
                tooltip: Format.tr('school_service.edit_child'),
                visualDensity: VisualDensity.compact,
                onPressed: () => showStudentForm(context, ref, student: student),
                icon: const Icon(Icons.edit_outlined, size: 18, color: AppColors.ink400),
              ),
            ],
          ),

          const SizedBox(height: AppSpacing.md),

          if (contract == null) ...[
            Text(Format.tr('school_service.no_contract'), style: theme.textTheme.bodySmall),
            const SizedBox(height: AppSpacing.md),
            FilledButton.icon(
              onPressed: () => showContractRequest(context, ref, student: student),
              icon: const Icon(Icons.assignment_outlined, size: 18),
              label: Text(Format.tr('school_service.request_contract')),
            ),
          ] else ...[
            _ContractSummary(contract: contract),
            const SizedBox(height: AppSpacing.md),
            // Live tracking is offered whatever the contract says; the server
            // decides whether there is anything to show, and refuses when the
            // child's own journey is over.
            if (contract.isActive)
              FilledButton.icon(
                onPressed: () => Navigator.of(context).push(
                  MaterialPageRoute<void>(
                    builder: (_) => SchoolLiveTrackingScreen(student: student),
                  ),
                ),
                icon: const Icon(Icons.location_on_outlined, size: 18),
                label: Text(Format.tr('school_service.track')),
              ),
          ],
        ],
      ),
    );
  }
}

class _ContractSummary extends StatelessWidget {
  const _ContractSummary({required this.contract});

  final SchoolContract contract;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.glassFill,
        borderRadius: AppRadii.fieldBorder,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  contract.companyName ?? '—',
                  style: theme.textTheme.titleSmall,
                ),
              ),
              StatusBadge(label: contract.statusLabel, colorToken: contract.statusColor),
            ],
          ),
          const SizedBox(height: 6),
          _Line(label: Format.tr('school_service.service'), value: contract.directionLabel),
          if (contract.feeAmount > 0)
            _Line(label: Format.tr('school_service.fee'), value: contract.formattedFee),

          // Null until the company puts the child on a van — the step that
          // turns an agreement into a seat, and the one a parent is waiting on.
          if (contract.hasSeat) ...[
            _Line(label: Format.tr('school_service.route'), value: contract.routeName!),
            if (contract.vehiclePlate != null)
              _Line(
                label: Format.tr('school_service.vehicle'),
                value: Format.digits(contract.vehiclePlate!),
              ),
            if (contract.driverName != null)
              _Line(
                label: Format.tr('school_service.driver'),
                value: contract.driverName!,
              ),
          ] else if (contract.isActive)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                Format.tr('school_service.awaiting_route'),
                style: theme.textTheme.labelSmall?.copyWith(color: AppColors.warning),
              ),
            ),

          if (contract.awaitingCompany)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                Format.tr('school_service.awaiting_company'),
                style: theme.textTheme.labelSmall?.copyWith(color: AppColors.warning),
              ),
            ),

          if (contract.rejectionReason != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(
                contract.rejectionReason!,
                style: theme.textTheme.labelSmall?.copyWith(color: AppColors.danger),
              ),
            ),
        ],
      ),
    );
  }
}

class _Line extends StatelessWidget {
  const _Line({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Text(label, style: theme.textTheme.labelSmall),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              value,
              textAlign: TextAlign.end,
              style: theme.textTheme.bodySmall,
            ),
          ),
        ],
      ),
    );
  }
}
