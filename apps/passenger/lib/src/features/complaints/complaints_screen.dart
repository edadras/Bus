import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:image_picker/image_picker.dart';

import '../../providers.dart';
import 'complaint_thread_screen.dart';

/// Complaint list plus the intake form. Photos matter here — most complaints
/// about a vehicle are far easier to act on with one — so attaching them is a
/// first-class part of the form rather than an afterthought.
class ComplaintsScreen extends ConsumerWidget {
  const ComplaintsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final complaints = ref.watch(complaintsProvider);

    return AppScaffold(
      title: 'پشتیبانی',
      subtitle: 'ثبت و پیگیری شکایات',
      onRefresh: () async {
        ref.invalidate(complaintsProvider);
        await ref.read(complaintsProvider.future);
      },
      floatingActionButton: Padding(
        padding: const EdgeInsets.only(bottom: 76),
        child: FloatingActionButton.extended(
          heroTag: 'new-complaint',
          backgroundColor: AppColors.brand500,
          onPressed: () async {
            final created = await Navigator.of(context).push<bool>(
              MaterialPageRoute(builder: (_) => const _ComplaintFormScreen()),
            );

            if (created == true) ref.invalidate(complaintsProvider);
          },
          icon: const Icon(Icons.add_rounded, color: Colors.white),
          label: const Text('ثبت شکایت', style: TextStyle(color: Colors.white)),
        ),
      ),
      body: complaints.when(
        loading: () => ListView.separated(
          itemCount: 3,
          separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
          itemBuilder: (_, __) => const ShimmerBox(height: 78),
        ),
        error: (error, _) => ErrorState(
          message: error is ApiException ? error.message : 'دریافت شکایات ممکن نشد.',
          isOffline: error is NetworkException,
          onRetry: () => ref.invalidate(complaintsProvider),
        ),
        data: (items) => items.isEmpty
            ? const EmptyState(
                icon: Icons.support_agent_rounded,
                message: 'شکایتی ثبت نکرده‌اید.\nدر صورت بروز مشکل، از دکمه پایین استفاده کنید.',
              )
            : ListView.separated(
                padding: const EdgeInsets.only(bottom: 150),
                itemCount: items.length,
                separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
                itemBuilder: (context, index) {
                  final complaint = items[index];

                  return GlassCard(
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                        builder: (_) => ComplaintThreadScreen(uuid: complaint.uuid),
                      ),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                complaint.subject,
                                style: Theme.of(context).textTheme.titleSmall,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                            StatusBadge(
                              label: complaint.statusLabel,
                              colorToken: complaint.statusColor,
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Row(
                          children: [
                            Text(
                              Format.digits(complaint.reference),
                              style: Theme.of(context).textTheme.labelSmall,
                            ),
                            const SizedBox(width: 10),
                            Text(
                              Format.relative(complaint.createdAt),
                              style: Theme.of(context).textTheme.labelSmall,
                            ),
                          ],
                        ),
                      ],
                    ),
                  );
                },
              ),
      ),
    );
  }
}

class _ComplaintFormScreen extends ConsumerStatefulWidget {
  const _ComplaintFormScreen();

  @override
  ConsumerState<_ComplaintFormScreen> createState() => _ComplaintFormScreenState();
}

class _ComplaintFormScreenState extends ConsumerState<_ComplaintFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _subjectController = TextEditingController();
  final _bodyController = TextEditingController();
  final _picker = ImagePicker();

  List<({String value, String label})> _categories = const [];
  String? _category;
  final List<XFile> _photos = [];
  bool _submitting = false;
  bool _attachLocation = true;

  @override
  void initState() {
    super.initState();
    unawaited(_loadCategories());
  }

  @override
  void dispose() {
    _subjectController.dispose();
    _bodyController.dispose();
    super.dispose();
  }

  Future<void> _loadCategories() async {
    try {
      final categories = await ref.read(transitApiProvider).complaintCategories();

      if (mounted) {
        setState(() {
          _categories = categories;
          _category = categories.firstOrNull?.value;
        });
      }
    } catch (_) {
      // The form is still usable; the picker simply stays empty until retry.
    }
  }

  Future<void> _addPhoto() async {
    if (_photos.length >= 5) return;

    final photo = await _picker.pickImage(
      source: ImageSource.camera,
      // Complaint photos are evidence, not art; compress hard so an upload
      // over a weak mobile connection actually completes.
      imageQuality: 70,
      maxWidth: 1600,
    );

    if (photo != null && mounted) setState(() => _photos.add(photo));
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false) || _category == null) return;

    setState(() => _submitting = true);

    final position = _attachLocation ? await ref.read(devicePositionProvider.future) : null;

    try {
      final attachments = <MultipartFile>[
        for (final photo in _photos)
          await MultipartFile.fromFile(photo.path, filename: photo.name),
      ];

      await ref.read(transitApiProvider).submitComplaint(
            category: _category!,
            subject: _subjectController.text.trim(),
            body: _bodyController.text.trim(),
            attachments: attachments,
            lat: position?.latitude,
            lng: position?.longitude,
          );

      if (!mounted) return;

      Navigator.of(context).pop(true);

      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('شکایت شما ثبت شد و در حال بررسی است.')),
      );
    } on ApiException catch (error) {
      if (mounted) {
        setState(() => _submitting = false);
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.message)));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return AppScaffold(
      title: 'ثبت شکایت',
      leading: const BackButton(),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.only(bottom: 40),
          children: [
            GlassCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('دسته‌بندی', style: Theme.of(context).textTheme.labelMedium),
                  const SizedBox(height: AppSpacing.sm),
                  DropdownButtonFormField<String>(
                    initialValue: _category,
                    dropdownColor: AppColors.ink800,
                    items: [
                      for (final category in _categories)
                        DropdownMenuItem(value: category.value, child: Text(category.label)),
                    ],
                    onChanged: (value) => setState(() => _category = value),
                    validator: (value) => value == null ? 'یک دسته‌بندی انتخاب کنید.' : null,
                  ),

                  const SizedBox(height: AppSpacing.lg),
                  Text('موضوع', style: Theme.of(context).textTheme.labelMedium),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _subjectController,
                    maxLength: 150,
                    decoration: const InputDecoration(
                      hintText: 'مثلاً: تأخیر طولانی خط ۱۰۲',
                      counterText: '',
                    ),
                    validator: (value) =>
                        (value == null || value.trim().length < 3) ? 'موضوع را وارد کنید.' : null,
                  ),

                  const SizedBox(height: AppSpacing.lg),
                  Text('شرح ماجرا', style: Theme.of(context).textTheme.labelMedium),
                  const SizedBox(height: AppSpacing.sm),
                  TextFormField(
                    controller: _bodyController,
                    maxLines: 5,
                    maxLength: 4000,
                    decoration: const InputDecoration(
                      hintText: 'هرچه دقیق‌تر بنویسید، رسیدگی سریع‌تر انجام می‌شود.',
                      counterText: '',
                    ),
                    validator: (value) => (value == null || value.trim().length < 10)
                        ? 'شرح باید حداقل ۱۰ نویسه باشد.'
                        : null,
                  ),
                ],
              ),
            ),

            const SizedBox(height: AppSpacing.md),

            GlassCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Text('تصاویر', style: Theme.of(context).textTheme.labelMedium),
                      const Spacer(),
                      Text(
                        '${Format.number(_photos.length)} از ۵',
                        style: Theme.of(context).textTheme.labelSmall,
                      ),
                    ],
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  Wrap(
                    spacing: AppSpacing.sm,
                    runSpacing: AppSpacing.sm,
                    children: [
                      for (var index = 0; index < _photos.length; index++)
                        Stack(
                          children: [
                            ClipRRect(
                              borderRadius: BorderRadius.circular(12),
                              child: Image.network(
                                _photos[index].path,
                                width: 72,
                                height: 72,
                                fit: BoxFit.cover,
                                errorBuilder: (_, __, ___) => Container(
                                  width: 72,
                                  height: 72,
                                  color: AppColors.ink700,
                                  child: const Icon(Icons.image, color: AppColors.ink400),
                                ),
                              ),
                            ),
                            Positioned(
                              top: 0,
                              left: 0,
                              child: GestureDetector(
                                onTap: () => setState(() => _photos.removeAt(index)),
                                child: Container(
                                  padding: const EdgeInsets.all(3),
                                  decoration: const BoxDecoration(
                                    color: AppColors.danger,
                                    shape: BoxShape.circle,
                                  ),
                                  child: const Icon(Icons.close, size: 12, color: Colors.white),
                                ),
                              ),
                            ),
                          ],
                        ),
                      if (_photos.length < 5)
                        GestureDetector(
                          onTap: _addPhoto,
                          child: Container(
                            width: 72,
                            height: 72,
                            decoration: BoxDecoration(
                              color: AppColors.glassFill,
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(color: AppColors.glassBorder),
                            ),
                            child: const Icon(Icons.add_a_photo_outlined, color: AppColors.ink400),
                          ),
                        ),
                    ],
                  ),

                  const SizedBox(height: AppSpacing.lg),
                  SwitchListTile(
                    value: _attachLocation,
                    onChanged: (value) => setState(() => _attachLocation = value),
                    contentPadding: EdgeInsets.zero,
                    title: Text('ارسال موقعیت مکانی', style: Theme.of(context).textTheme.titleSmall),
                    subtitle: Text(
                      'به کارشناس کمک می‌کند محل دقیق رویداد را تشخیص دهد.',
                      style: Theme.of(context).textTheme.labelSmall,
                    ),
                  ),
                ],
              ),
            ),

            const SizedBox(height: AppSpacing.lg),
            FilledButton(
              onPressed: _submitting ? null : _submit,
              child: _submitting
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                    )
                  : const Text('ارسال شکایت'),
            ),
          ],
        ),
      ),
    );
  }
}
