import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:latlong2/latlong.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../providers.dart';

/// Where my child's van is.
///
/// The privacy rule is the server's, and this screen is built around it rather
/// than around a map: a family sees the van that is carrying their child,
/// while it is carrying them, and nothing at any other time. "No run under
/// way" is the ordinary answer for most of the day and is shown as an answer,
/// not as an error.
///
/// The estimate leans late on purpose. A van that arrives early is a nuisance;
/// a van a parent missed is a child standing on a pavement.
class SchoolLiveTrackingScreen extends ConsumerWidget {
  const SchoolLiveTrackingScreen({required this.student, super.key});

  final SchoolStudent student;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final live = ref.watch(schoolLiveProvider(student.uuid));

    return AppScaffold(
      title: student.name,
      subtitle: Format.tr('school_service.tracking'),
      leading: const BackButton(),
      padded: false,
      body: live.when(
        loading: () => const Center(child: CircularProgressIndicator(color: AppColors.brand400)),
        error: (error, _) => Padding(
          padding: const EdgeInsets.all(AppSpacing.lg),
          child: ErrorState(
            message: error is ApiException ? error.message : Format.tr('school_service.load_failed'),
            isOffline: error is NetworkException,
            onRetry: () => ref.invalidate(schoolLiveProvider(student.uuid)),
          ),
        ),
        data: (result) {
          final view = result.view;

          if (view == null) {
            return Padding(
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  EmptyState(
                    icon: Icons.schedule_rounded,
                    message: Format.tr('school_service.no_run'),
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  _AttendanceHistory(studentUuid: student.uuid),
                ],
              ),
            );
          }

          return ListView(
            padding: const EdgeInsets.fromLTRB(
              AppSpacing.lg,
              0,
              AppSpacing.lg,
              AppSpacing.xl,
            ),
            children: [
              _EtaCard(view: view),
              const SizedBox(height: AppSpacing.md),
              if (view.position != null) ...[
                _VanMap(view: view, student: student),
                const SizedBox(height: AppSpacing.md),
              ],
              _VehicleCard(view: view),
              const SizedBox(height: AppSpacing.lg),
              _AttendanceHistory(studentUuid: student.uuid),
            ],
          );
        },
      ),
    );
  }
}

class _EtaCard extends StatelessWidget {
  const _EtaCard({required this.view});

  final SchoolLiveView view;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      strong: true,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const LiveDot(),
              const SizedBox(width: 8),
              Text(view.directionLabel, style: theme.textTheme.titleSmall),
              const Spacer(),
              StatusBadge(
                label: view.childStatusLabel,
                colorToken: view.isAboard ? 'success' : 'warning',
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.lg),
          Center(
            child: Column(
              children: [
                Text(
                  view.isAboard
                      ? Format.tr('school_service.aboard_since')
                      : Format.tr('school_service.arrives_in'),
                  style: theme.textTheme.labelMedium,
                ),
                const SizedBox(height: 4),
                Text(
                  view.isAboard
                      ? Format.time(view.pickedUpAt)
                      : view.isArriving
                          ? Format.tr('school_service.arriving_now')
                          : Format.tr('unit.minutes', {
                              'count': Format.number(view.etaMinutes ?? 0),
                            }),
                  style: theme.textTheme.displaySmall?.copyWith(color: AppColors.brand300),
                ),
              ],
            ),
          ),
          if (!view.isAboard) ...[
            const SizedBox(height: AppSpacing.lg),
            Row(
              children: [
                Expanded(
                  child: StatTile(
                    label: Format.tr('school_service.distance'),
                    value: Format.distance(view.distanceMeters),
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: StatTile(
                    label: Format.tr('school_service.stops_ahead'),
                    value: Format.number(view.stopsAhead),
                  ),
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            // Always said, never implied: a straight line is not a road.
            Text(
              Format.tr('school_service.eta_approximate'),
              style: theme.textTheme.labelSmall,
            ),
          ],
        ],
      ),
    );
  }
}

class _VanMap extends ConsumerWidget {
  const _VanMap({required this.view, required this.student});

  final SchoolLiveView view;
  final SchoolStudent student;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(mapConfigProvider);
    final van = LatLng(view.position!.lat, view.position!.lng);
    final door = student.pickupPoint;

    return SizedBox(
      height: 260,
      child: ClipRRect(
        borderRadius: AppRadii.cardBorder,
        child: config.when(
          loading: () => const ShimmerBox(height: 260, radius: AppRadii.card),
          error: (_, __) => ErrorState(message: Format.tr('map.unavailable'), isOffline: true),
          data: (map) => FlutterMap(
            options: MapOptions(initialCenter: van, initialZoom: 15, minZoom: 10),
            children: [
              TileLayer(
                urlTemplate: map.tileUrl,
                userAgentPackageName: 'com.hamsafar.passenger',
                maxZoom: map.maxZoom.toDouble(),
              ),
              if (door != null)
                MarkerLayer(
                  markers: [
                    Marker(
                      point: LatLng(door.lat, door.lng),
                      width: 20,
                      height: 20,
                      child: const DecoratedBox(
                        decoration: BoxDecoration(
                          color: AppColors.info,
                          shape: BoxShape.circle,
                          border: Border.fromBorderSide(
                            BorderSide(color: Colors.white, width: 3),
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              MarkerLayer(
                markers: [
                  Marker(
                    point: van,
                    width: 40,
                    height: 40,
                    child: Container(
                      decoration: BoxDecoration(
                        color: AppColors.brand500,
                        borderRadius: BorderRadius.circular(13),
                        border: Border.all(color: Colors.white, width: 2),
                        boxShadow: const [
                          BoxShadow(color: Color(0x80000000), blurRadius: 10),
                        ],
                      ),
                      child: const Icon(
                        Icons.airport_shuttle_rounded,
                        size: 20,
                        color: Colors.white,
                      ),
                    ),
                  ),
                ],
              ),
              RichAttributionWidget(
                attributions: [TextSourceAttribution(map.attribution, onTap: null)],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _VehicleCard extends StatelessWidget {
  const _VehicleCard({required this.view});

  final SchoolLiveView view;

  Future<void> _call() async {
    if (view.driverPhone == null) return;

    final uri = Uri(scheme: 'tel', path: Format.toLatinDigits(view.driverPhone!));

    if (await canLaunchUrl(uri)) await launchUrl(uri);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return GlassCard(
      child: Row(
        children: [
          const Icon(Icons.airport_shuttle_outlined, size: 20, color: AppColors.ink400),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(view.driverName ?? '—', style: theme.textTheme.titleSmall),
                Text(
                  Format.digits(
                    [view.plate, view.model, view.color].whereType<String>().join(' · '),
                  ),
                  style: theme.textTheme.labelSmall,
                ),
                if (view.reportedAt != null)
                  Text(
                    Format.tr('school_service.reported', {
                      'when': Format.relative(view.reportedAt),
                    }),
                    style: theme.textTheme.labelSmall,
                  ),
              ],
            ),
          ),
          // A parent standing on a pavement with a van that has not arrived
          // needs to reach somebody.
          if (view.driverPhone != null)
            IconButton(
              onPressed: _call,
              icon: const Icon(Icons.phone_rounded, size: 20, color: AppColors.brand300),
            ),
        ],
      ),
    );
  }
}

/// What happened on the recent runs, which is what a parent checks at night.
class _AttendanceHistory extends ConsumerWidget {
  const _AttendanceHistory({required this.studentUuid});

  final String studentUuid;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final rows = ref.watch(schoolAttendanceProvider(studentUuid));
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 4),
          child: Text(Format.tr('school_service.history'), style: theme.textTheme.titleSmall),
        ),
        const SizedBox(height: AppSpacing.sm),
        rows.when(
          loading: () => const ShimmerBox(height: 66),
          error: (_, __) => EmptyState(message: Format.tr('school_service.history_failed')),
          data: (items) => items.isEmpty
              ? EmptyState(
                  icon: Icons.history_rounded,
                  message: Format.tr('school_service.no_history'),
                )
              : Column(
                  children: [
                    for (final row in items.take(20))
                      Padding(
                        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                        child: GlassCard(
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Text(
                                      Format.digits(row.serviceDate ?? '—'),
                                      style: theme.textTheme.titleSmall,
                                    ),
                                    Text(
                                      row.directionLabel ?? '',
                                      style: theme.textTheme.labelSmall,
                                    ),
                                  ],
                                ),
                              ),
                              if (row.pickedUpAt != null)
                                Text(
                                  Format.time(row.pickedUpAt),
                                  style: theme.textTheme.labelSmall,
                                ),
                              const SizedBox(width: 8),
                              StatusBadge(label: row.statusLabel, colorToken: 'neutral'),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
        ),
      ],
    );
  }
}
