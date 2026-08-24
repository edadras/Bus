import 'package:flutter/material.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

/// One child on the manifest.
///
/// Everything a driver needs at a door, on one card: the name, where to stop,
/// a medical note if there is one, and a number to call when nobody comes out.
/// The action is a single wide button because it is pressed one-handed, from a
/// driver's seat, and a row of small controls would be pressed wrongly.
class StudentCard extends StatelessWidget {
  const StudentCard({
    required this.student,
    required this.enabled,
    required this.isMorning,
    required this.onPickUp,
    required this.onDropOff,
    required this.onAbsent,
    required this.onReset,
    required this.onCallGuardian,
    required this.onCallEmergency,
    super.key,
  });

  final SchoolTripStudent student;
  final bool enabled;
  final bool isMorning;
  final VoidCallback onPickUp;
  final VoidCallback onDropOff;
  final VoidCallback onAbsent;
  final VoidCallback onReset;
  final VoidCallback onCallGuardian;
  final VoidCallback onCallEmergency;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      strong: student.isAboard,
      padding: const EdgeInsets.all(AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              _SequenceBadge(sequence: student.sequence, settled: student.isSettled),
              const SizedBox(width: AppSpacing.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(student.name, style: theme.textTheme.titleSmall),
                    if (student.grade != null)
                      Text(student.grade!, style: theme.textTheme.labelSmall),
                    if (student.pickupAddress != null) ...[
                      const SizedBox(height: 4),
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Icon(Icons.place_outlined, size: 13, color: AppColors.ink400),
                          const SizedBox(width: 4),
                          Expanded(
                            child: Text(
                              student.pickupAddress!,
                              style: theme.textTheme.labelSmall,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ],
                ),
              ),
              StatusBadge(label: student.statusLabel, colorToken: student.statusColor),
            ],
          ),

          // Carried here rather than in a file somebody would have to go and
          // find: this is the screen a driver is looking at when it matters.
          if (student.medicalNotes != null && student.medicalNotes!.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              decoration: BoxDecoration(
                color: AppColors.danger.withValues(alpha: 0.10),
                borderRadius: AppRadii.fieldBorder,
              ),
              child: Row(
                children: [
                  const Icon(Icons.medical_information_outlined,
                      size: 15, color: AppColors.danger),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      student.medicalNotes!,
                      style: theme.textTheme.labelSmall?.copyWith(color: AppColors.danger),
                    ),
                  ),
                ],
              ),
            ),
          ],

          if (student.pickedUpAt != null || student.droppedOffAt != null) ...[
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: [
                if (student.pickedUpAt != null) ...[
                  const Icon(Icons.login_rounded, size: 13, color: AppColors.ink400),
                  const SizedBox(width: 4),
                  Text(Format.time(student.pickedUpAt), style: theme.textTheme.labelSmall),
                  const SizedBox(width: AppSpacing.md),
                ],
                if (student.droppedOffAt != null) ...[
                  const Icon(Icons.logout_rounded, size: 13, color: AppColors.ink400),
                  const SizedBox(width: 4),
                  Text(Format.time(student.droppedOffAt), style: theme.textTheme.labelSmall),
                ],
              ],
            ),
          ],

          const SizedBox(height: AppSpacing.md),
          Row(
            children: [
              if (student.guardianPhone != null)
                _CallButton(
                  icon: Icons.phone_rounded,
                  label: Format.tr('school.call_guardian'),
                  onTap: onCallGuardian,
                ),
              if (student.emergencyContactPhone != null) ...[
                const SizedBox(width: AppSpacing.sm),
                _CallButton(
                  icon: Icons.emergency_outlined,
                  label: Format.tr('school.call_emergency'),
                  onTap: onCallEmergency,
                ),
              ],
              const Spacer(),
              if (student.isSettled && enabled)
                TextButton.icon(
                  onPressed: onReset,
                  icon: const Icon(Icons.undo_rounded, size: 16),
                  label: Text(Format.tr('school.undo')),
                ),
            ],
          ),

          if (student.isPending) ...[
            const SizedBox(height: AppSpacing.sm),
            Row(
              children: [
                Expanded(
                  flex: 3,
                  child: FilledButton.icon(
                    onPressed: enabled ? onPickUp : null,
                    icon: const Icon(Icons.check_rounded, size: 18),
                    label: Text(
                      isMorning
                          ? Format.tr('school.picked_up_action')
                          : Format.tr('school.boarded_action'),
                    ),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: OutlinedButton(
                    onPressed: enabled ? onAbsent : null,
                    child: Text(Format.tr('school.absent_action')),
                  ),
                ),
              ],
            ),
          ] else if (student.isAboard) ...[
            const SizedBox(height: AppSpacing.sm),
            FilledButton.icon(
              onPressed: enabled ? onDropOff : null,
              style: FilledButton.styleFrom(backgroundColor: AppColors.brand600),
              icon: const Icon(Icons.flag_rounded, size: 18),
              label: Text(
                isMorning
                    ? Format.tr('school.dropped_at_school')
                    : Format.tr('school.dropped_at_home'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _SequenceBadge extends StatelessWidget {
  const _SequenceBadge({required this.sequence, required this.settled});

  final int sequence;
  final bool settled;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 30,
      height: 30,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: settled
            ? AppColors.brand500.withValues(alpha: 0.18)
            : AppColors.glassFillStrong,
        borderRadius: BorderRadius.circular(10),
      ),
      child: settled
          ? const Icon(Icons.check_rounded, size: 16, color: AppColors.brand300)
          : Text(
              Format.number(sequence),
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700),
            ),
    );
  }
}

class _CallButton extends StatelessWidget {
  const _CallButton({required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return TextButton.icon(
      onPressed: onTap,
      icon: Icon(icon, size: 16),
      label: Text(label),
      style: TextButton.styleFrom(
        foregroundColor: AppColors.ink300,
        padding: const EdgeInsets.symmetric(horizontal: 8),
        visualDensity: VisualDensity.compact,
      ),
    );
  }
}
