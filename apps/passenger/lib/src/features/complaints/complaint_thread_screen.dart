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

  @override
  Widget build(BuildContext context) {
    final complaint = ref.watch(_complaintProvider(widget.uuid));

    return AppScaffold(
      title: 'پیگیری شکایت',
      leading: const BackButton(),
      body: complaint.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.brand400)),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : 'دریافت اطلاعات ممکن نشد.',
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
                  for (final message in data.messages) ...[
                    _MessageBubble(message: message),
                    const SizedBox(height: AppSpacing.sm),
                  ],
                  if (data.messages.isEmpty)
                    const EmptyState(
                      icon: Icons.mark_chat_read_outlined,
                      message: 'هنوز پاسخی ثبت نشده است. کارشناسان در حال بررسی هستند.',
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
                          decoration: const InputDecoration(hintText: 'پاسخ خود را بنویسید…'),
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
                message.authorName ?? (fromSupport ? 'تیم پشتیبانی' : 'شما'),
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
