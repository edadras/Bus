import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

import '../../providers.dart';

/// Register or edit a child.
///
/// The medical note and the emergency number are asked for here because the
/// only screen they ever appear on afterwards is the driver's manifest, at the
/// moment either becomes relevant.
Future<void> showStudentForm(
  BuildContext context,
  WidgetRef ref, {
  SchoolStudent? student,
}) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (_) => _StudentFormSheet(student: student),
  );
}

class _StudentFormSheet extends ConsumerStatefulWidget {
  const _StudentFormSheet({this.student});

  final SchoolStudent? student;

  @override
  ConsumerState<_StudentFormSheet> createState() => _StudentFormSheetState();
}

class _StudentFormSheetState extends ConsumerState<_StudentFormSheet> {
  late final _firstName = TextEditingController(text: widget.student?.firstName);
  late final _lastName = TextEditingController(text: widget.student?.lastName);
  late final _grade = TextEditingController(text: widget.student?.grade);
  late final _address = TextEditingController(text: widget.student?.pickupAddress);
  late final _medical = TextEditingController(text: widget.student?.medicalNotes);
  late final _emergencyName =
      TextEditingController(text: widget.student?.emergencyContactName);
  late final _emergencyPhone =
      TextEditingController(text: widget.student?.emergencyContactPhone);

  late String? _schoolUuid = widget.student?.schoolUuid;
  late String _gender = widget.student?.gender ?? 'female';
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    for (final controller in [
      _firstName,
      _lastName,
      _grade,
      _address,
      _medical,
      _emergencyName,
      _emergencyPhone,
    ]) {
      controller.dispose();
    }

    super.dispose();
  }

  Future<void> _save() async {
    if (_firstName.text.trim().isEmpty || _lastName.text.trim().isEmpty) {
      setState(() => _error = Format.tr('school_service.name_required'));

      return;
    }

    setState(() {
      _busy = true;
      _error = null;
    });

    try {
      final position = await ref.read(devicePositionProvider.future);

      await ref.read(transitApiProvider).saveSchoolStudent(
        {
          'first_name': _firstName.text.trim(),
          'last_name': _lastName.text.trim(),
          if (_schoolUuid != null) 'school_uuid': _schoolUuid,
          if (_grade.text.trim().isNotEmpty) 'grade': _grade.text.trim(),
          'gender': _gender,
          if (_address.text.trim().isNotEmpty) 'pickup_address': _address.text.trim(),
          // Attached only when the parent is filling this in at the door they
          // want the van to stop at, which is the common case.
          if (widget.student == null && position != null) 'pickup_lat': position.latitude,
          if (widget.student == null && position != null) 'pickup_lng': position.longitude,
          if (_medical.text.trim().isNotEmpty) 'medical_notes': _medical.text.trim(),
          if (_emergencyName.text.trim().isNotEmpty)
            'emergency_contact_name': _emergencyName.text.trim(),
          if (_emergencyPhone.text.trim().isNotEmpty)
            'emergency_contact_phone': Format.toLatinDigits(_emergencyPhone.text.trim()),
        },
        uuid: widget.student?.uuid,
      );

      ref.invalidate(schoolStudentsProvider);

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
    final schools = ref.watch(schoolsListProvider);

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
                  widget.student == null
                      ? Format.tr('school_service.add_child')
                      : Format.tr('school_service.edit_child'),
                  style: theme.textTheme.titleMedium,
                ),
                const SizedBox(height: AppSpacing.lg),

                TextField(
                  controller: _firstName,
                  decoration: InputDecoration(labelText: Format.tr('school_service.first_name')),
                ),
                const SizedBox(height: AppSpacing.sm),
                TextField(
                  controller: _lastName,
                  decoration: InputDecoration(labelText: Format.tr('school_service.last_name')),
                ),
                const SizedBox(height: AppSpacing.sm),

                schools.when(
                  loading: () => const ShimmerBox(height: 52),
                  error: (_, __) => const SizedBox.shrink(),
                  data: (items) => DropdownButtonFormField<String>(
                    initialValue:
                        items.any((school) => school.uuid == _schoolUuid) ? _schoolUuid : null,
                    dropdownColor: AppColors.ink800,
                    isExpanded: true,
                    decoration: InputDecoration(labelText: Format.tr('school_service.school')),
                    items: [
                      for (final school in items)
                        DropdownMenuItem(
                          value: school.uuid,
                          child: Text(school.name, overflow: TextOverflow.ellipsis),
                        ),
                    ],
                    onChanged: (value) => setState(() => _schoolUuid = value),
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),

                TextField(
                  controller: _grade,
                  decoration: InputDecoration(labelText: Format.tr('school_service.grade')),
                ),
                const SizedBox(height: AppSpacing.md),

                SegmentedButton<String>(
                  segments: [
                    ButtonSegment(
                      value: 'female',
                      label: Text(Format.tr('school_service.girl')),
                    ),
                    ButtonSegment(
                      value: 'male',
                      label: Text(Format.tr('school_service.boy')),
                    ),
                  ],
                  selected: {_gender},
                  onSelectionChanged: (values) => setState(() => _gender = values.first),
                ),
                const SizedBox(height: AppSpacing.md),

                TextField(
                  controller: _address,
                  maxLines: 2,
                  decoration: InputDecoration(
                    labelText: Format.tr('school_service.pickup_address'),
                    helperText: Format.tr('school_service.pickup_help'),
                  ),
                ),
                const SizedBox(height: AppSpacing.md),

                TextField(
                  controller: _medical,
                  maxLines: 2,
                  decoration: InputDecoration(
                    labelText: Format.tr('school_service.medical_notes'),
                    helperText: Format.tr('school_service.medical_help'),
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                TextField(
                  controller: _emergencyName,
                  decoration: InputDecoration(
                    labelText: Format.tr('school_service.emergency_name'),
                  ),
                ),
                const SizedBox(height: AppSpacing.sm),
                TextField(
                  controller: _emergencyPhone,
                  keyboardType: TextInputType.phone,
                  textDirection: TextDirection.ltr,
                  decoration: InputDecoration(
                    labelText: Format.tr('school_service.emergency_phone'),
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
                  onPressed: _busy ? null : _save,
                  child: Text(
                    _busy ? Format.tr('school_service.saving') : Format.tr('school_service.save'),
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
