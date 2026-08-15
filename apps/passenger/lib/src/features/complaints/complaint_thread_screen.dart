import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

final _complaintProvider = FutureProvider.family<Complaint, String>(
  (ref, uuid) => ref.watch(transitApiProvider).complaint(uuid),
);

/// The conversation on one complaint. Only the passenger-visible thread is
/// ever returned by the API, so internal triage notes cannot leak here.
class ComplaintThreadScreen extends ConsumerStatefulWidget {
  const ComplaintThreadScreen({required this.uuid, super.key});

  final String uuid;

  @override
  ConsumerState<ComplaintThreadScreen> createState() => _ComplaintThreadScreenState();
}

class _ComplaintThreadScreenState extends ConsumerState<ComplaintThreadScreen> {
  final _replyController = TextEditingController();
  bool _sending = false;
  bool _rating = false;

  @override
  void dispose() {
    _replyController.dispose();
    super.dispose();
  }

  Future<void> _reply() async {
    final body = _replyController.text.trim();

    if (body.length < 2) return;

    setState(() => _sending = true);

    try {
      await ref.read(transitApiProvider).replyToComplaint(widget.uuid, body);

      _replyController.clear();
      ref.invalidate(_complaintProvider(widget.uuid));
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _rate(int rating) async {
    setState(() => _rating = true);

    try {
      await ref.read(transitApiProvider).rateComplaint(widget.uuid, rating);

      ref.invalidate(_complaintProvider(widget.uuid));

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(Format.tr('complaints.rating_thanks'))),
        );
      }
    } on ApiException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    } finally {
      if (mounted) setState(() => _rating = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final complaint = ref.watch(_complaintProvider(widget.uuid));

    return AppScaffold(
      title: Format.tr('complaints.thread_title'),
      leading: const BackButton(),
      body: complaint.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.brand400)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : Format.tr('complaints.thread_failed'),
          onRetry: () => ref.invalidate(_complaintProvider(widget.uuid)),
        ),
        data: (data) => Column(
          children: [
            Expanded(
              child: ListView(
                padding: const EdgeInsets.only(bottom: AppSpacing.lg),
                children: [
                  GlassCard(
                    strong: true,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(data.subject,
                                  style: Theme.of(context).textTheme.titleMedium),
                            ),
                            StatusBadge(label: data.statusLabel, colorToken: data.statusColor),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Text(
                          '${Format.digits(data.reference)} · ${data.categoryLabel ?? ''}',
                          style: Theme.of(context).textTheme.labelSmall,
                        ),
                        if (data.body != null) ...[
                          const Divider(height: AppSpacing.xl),
                          Text(data.body!, style: Theme.of(context).textTheme.bodyMedium),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  // Asked once, at the only moment it means anything: the case
                  // is closed and the passenger has not yet said whether that
                  // was the right outcome.
                  if (data.awaitsRating) ...[
                    _RatingCard(
                      onRate: (rating) => _rate(rating),
                      busy: _rating,
                    ),
                    const SizedBox(height: AppSpacing.lg),
                  ],
                  if (data.satisfactionRating != null) ...[
                    GlassCard(
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              Format.tr('complaints.rated'),
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ),
                          _Stars(value: data.satisfactionRating!),
                        ],
                      ),
                    ),
                    const SizedBox(height: AppSpacing.lg),
                  ],
                  for (final message in data.messages) ...[
                    _MessageBubble(message: message),
                    const SizedBox(height: AppSpacing.sm),
                  ],
                  if (data.messages.isEmpty)
                    EmptyState(
                      icon: Icons.mark_chat_read_outlined,
                      message: Format.tr('complaints.no_replies'),
                    ),
                ],
              ),
            ),
            if (data.isOpen)
              SafeArea(
                child: Padding(
                  padding: const EdgeInsets.only(top: AppSpacing.sm, bottom: AppSpacing.sm),
                  child: Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _replyController,
                          decoration: InputDecoration(hintText: Format.tr('complaints.reply_hint')),
                          maxLines: 3,
                          minLines: 1,
                        ),
                      ),
                      const SizedBox(width: AppSpacing.sm),
                      IconButton.filled(
                        onPressed: _sending ? null : _reply,
                        icon: _sending
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(strokeWidth: 2),
                              )
                            : const Icon(Icons.send_rounded, size: 20),
                        style: IconButton.styleFrom(backgroundColor: AppColors.brand500),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _RatingCard extends StatelessWidget {
  const _RatingCard({required this.onRate, required this.busy});

  final ValueChanged<int> onRate;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      strong: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(Format.tr('complaints.rate_title'), style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: 4),
          Text(Format.tr('complaints.rate_body'), style: Theme.of(context).textTheme.labelSmall),
          const SizedBox(height: AppSpacing.md),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: [
              for (var value = 1; value <= 5; value++)
                IconButton(
                  onPressed: busy ? null : () => onRate(value),
                  icon: const Icon(Icons.star_rounded, size: 30),
                  color: AppColors.brand300,
                  tooltip: Format.tr('complaints.rate_of_five', {
                    'count': Format.number(value),
                  }),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Stars extends StatelessWidget {
  const _Stars({required this.value});

  final int value;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var index = 1; index <= 5; index++)
          Icon(
            index <= value ? Icons.star_rounded : Icons.star_outline_rounded,
            size: 18,
            color: index <= value ? AppColors.brand300 : AppColors.ink500,
          ),
      ],
    );
  }
}

class _MessageBubble extends StatelessWidget {
  const _MessageBubble({required this.message});

  final ComplaintMessage message;

  @override
  Widget build(BuildContext context) {
    final fromSupport = message.isFromSupport;

    return Align(
      alignment: fromSupport ? Alignment.centerRight : Alignment.centerLeft,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.82),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
          decoration: BoxDecoration(
            color: fromSupport ? AppColors.brand500.withValues(alpha: 0.16) : AppColors.glassFill,
            borderRadius: BorderRadius.only(
              topLeft: const Radius.circular(16),
              topRight: const Radius.circular(16),
              bottomLeft: Radius.circular(fromSupport ? 16 : 4),
              bottomRight: Radius.circular(fromSupport ? 4 : 16),
            ),
            border: Border.all(
              color:
                  fromSupport ? AppColors.brand500.withValues(alpha: 0.28) : AppColors.glassBorder,
            ),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                message.authorName ??
                    (fromSupport
                        ? Format.tr('complaints.support_team')
                        : Format.tr('complaints.you')),
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                      color: fromSupport ? AppColors.brand300 : AppColors.ink400,
                    ),
              ),
              const SizedBox(height: 4),
              Text(message.body, style: Theme.of(context).textTheme.bodyMedium),
              const SizedBox(height: 4),
              Text(
                Format.relative(message.createdAt),
                style: Theme.of(context).textTheme.labelSmall?.copyWith(fontSize: 10),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
