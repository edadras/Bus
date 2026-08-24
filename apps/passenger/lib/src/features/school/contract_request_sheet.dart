import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// Ask a company for a seat.
///
/// The list is only ever approved companies — the server does that filtering,
/// not this screen — because a parent choosing who drives their child should
/// not have to work out which of the names on a list was vetted.
///
/// Nothing here names a price. The company answers with the fee, the parent
/// sees it before anything is owed, and only then does the arrangement become
/// a contract.
Future<void> showContractRequest(
  BuildContext context,
  WidgetRef ref, {
  required SchoolStudent student,
}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (_) => _ContractRequestSheet(student: student),
  );
}

class _ContractRequestSheet extends ConsumerStatefulWidget {
  const _ContractRequestSheet({required this.student});

  final SchoolStudent student;

  @override
  ConsumerState<_ContractRequestSheet> createState() => _ContractRequestSheetState();
}

class _ContractRequestSheetState extends ConsumerState<_ContractRequestSheet> {
  final _note = TextEditingController();
  late final _address =
      TextEditingController(text: widget.student.pickupAddress);

  String? _companyUuid;
  String _direction = 'both';

  /// Saturday through Wednesday: the ordinary Iranian school week, and the
  /// answer for almost every family. ISO weekday numbers.
  final Set<int> _days = {6, 7, 1, 2, 3};

  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _note.dispose();
    _address.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_companyUuid == null) {
      setState(() => _error = Format.tr('school_service.company_required'));

      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final position = await ref.read(devicePositionProvider.future);

      await ref.read(transitApiProvider).requestSchoolContract(
            studentUuid: widget.student.uuid,
            companyUuid: _companyUuid!,
            direction: _direction,
            daysOfWeek: _days.toList()..sort(),
            pickupAddress:
                _address.text.trim().isEmpty ? null : _address.text.trim(),
            pickupLat: widget.student.pickupPoint?.lat ?? position?.latitude,
            pickupLng: widget.student.pickupPoint?.lng ?? position?.longitude,
            note: _note.text.trim().isEmpty ? null : _note.text.trim(),
          );

      ref.invalidate(schoolContractsProvider);

      if (mounted) Navigator.pop(context);
    } on ApiException catch (error) {
      if (mounted) {
        setState(() {
          _error = error.message;
          _busy = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final companies = ref.watch(schoolCompaniesProvider);

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: SafeArea(
        child: Container(
          margin: const EdgeInsets.all(AppSpacing.md),
          padding: const EdgeInsets.all(AppSpacing.lg),
          constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.9),
          decoration: BoxDecoration(
            color: AppColors.ink850,
            borderRadius: AppRadii.cardBorder,
            border: Border.all(color: AppColors.glassBorderStrong),
          ),
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  Format.tr('school_service.request_for', {'name': widget.student.name}),
                  style: theme.textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  Format.tr('school_service.request_body'),
                  style: theme.textTheme.bodySmall,
                ),
                const SizedBox(height: AppSpacing.lg),

                Text(Format.tr('school_service.company'), style: theme.textTheme.labelMedium),
                const SizedBox(height: AppSpacing.sm),
                companies.when(
                  loading: () => const ShimmerBox(height: 72),
                  error: (_, __) => Text(
                    Format.tr('school_service.companies_failed'),
                    style: theme.textTheme.bodySmall,
                  ),
                  data: (items) => items.isEmpty
                      ? Text(
                          Format.tr('school_service.no_companies'),
                          style: theme.textTheme.bodySmall,
                        )
                      : Column(
                          children: [
                            for (final company in items)
                              Padding(
                                padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                                child: _CompanyTile(
                                  company: company,
                                  selected: company.uuid == _companyUuid,
                                  onTap: () => setState(() => _companyUuid = company.uuid),
                                ),
                              ),
                          ],
                        ),
                ),

                const SizedBox(height: AppSpacing.md),
                Text(Format.tr('school_service.service'), style: theme.textTheme.labelMedium),
                const SizedBox(height: AppSpacing.sm),
                SegmentedButton<String>(
                  segments: [
                    ButtonSegment(
                      value: 'both',
                      label: Text(Format.tr('school_service.both_ways')),
                    ),
                    ButtonSegment(
                      value: 'to_school',
                      label: Text(Format.tr('school_service.to_school')),
                    ),
                    ButtonSegment(
                      value: 'from_school',
                      label: Text(Format.tr('school_service.from_school')),
                    ),
                  ],
                  selected: {_direction},
                  onSelectionChanged: (values) => setState(() => _direction = values.first),
                ),

                const SizedBox(height: AppSpacing.md),
                Text(Format.tr('school_service.days'), style: theme.textTheme.labelMedium),
                const SizedBox(height: AppSpacing.sm),
                Wrap(
                  spacing: AppSpacing.sm,
                  children: [
                    for (final day in const [6, 7, 1, 2, 3, 4, 5])
                      FilterChip(
                        label: Text(Format.tr('school_service.weekdays.$day')),
                        selected: _days.contains(day),
                        onSelected: (selected) => setState(() {
                          if (selected) {
                            _days.add(day);
                          } else {
                            _days.remove(day);
                          }
                        }),
                      ),
                  ],
                ),

                const SizedBox(height: AppSpacing.md),
                TextField(
                  controller: _address,
                  maxLines: 2,
                  decoration: InputDecoration(
                    labelText: Format.tr('school_service.pickup_address'),
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                TextField(
                  controller: _note,
                  maxLines: 2,
                  decoration: InputDecoration(
                    labelText: Format.tr('school_service.note'),
                    helperText: Format.tr('school_service.note_help'),
                  ),
                ),

                if (_error != null) ...[
                  const SizedBox(height: AppSpacing.md),
                  Text(
                    _error!,
                    style: theme.textTheme.bodySmall?.copyWith(color: AppColors.danger),
                  ),
                ],

                const SizedBox(height: AppSpacing.lg),
                FilledButton(
                  onPressed: _busy || _days.isEmpty ? null : _submit,
                  child: Text(
                    _busy
                        ? Format.tr('school_service.sending')
                        : Format.tr('school_service.send_request'),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _CompanyTile extends StatelessWidget {
  const _CompanyTile({required this.company, required this.selected, required this.onTap});

  final SchoolCompany company;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return InkWell(
      onTap: onTap,
      borderRadius: AppRadii.fieldBorder,
      child: Container(
        padding: const EdgeInsets.all(AppSpacing.md),
        decoration: BoxDecoration(
          color: selected ? AppColors.brand500.withValues(alpha: 0.12) : AppColors.glassFill,
          borderRadius: AppRadii.fieldBorder,
          border: Border.all(
            color: selected ? AppColors.brand500 : AppColors.glassBorder,
          ),
        ),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(company.name, style: theme.textTheme.titleSmall),
                  const SizedBox(height: 2),
                  Text(
                    [
                      if (company.vehicleCount != null)
                        Format.tr('school_service.vehicle_count', {
                          'count': Format.number(company.vehicleCount),
                        }),
                      Format.tr('school_service.family_count', {
                        'count': Format.number(company.contractCount),
                      }),
                    ].join(' · '),
                    style: theme.textTheme.labelSmall,
                  ),
                  if (company.description != null) ...[
                    const SizedBox(height: 4),
                    Text(company.description!, style: theme.textTheme.labelSmall),
                  ],
                ],
              ),
            ),
            if (company.rating != null)
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.star_rounded, size: 14, color: AppColors.warning),
                  const SizedBox(width: 2),
                  Text(
                    Format.number(company.rating),
                    style: theme.textTheme.labelSmall,
                  ),
                ],
              ),
            if (selected)
              const Padding(
                padding: EdgeInsets.only(right: 6),
                child: Icon(Icons.check_circle_rounded, size: 18, color: AppColors.brand400),
              ),
          ],
        ),
      ),
    );
  }
}
