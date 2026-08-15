import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hamsafar_core/hamsafar_core.dart';
import 'package:latlong2/latlong.dart';

import '../../providers.dart';
import 'widgets/arrival_tile.dart';
import 'widgets/bus_marker.dart';
import 'widgets/trip_sheet.dart';

/// The passenger's home screen: a live map on top, the arrival board for the
/// nearest stop below. Both are usable without an account.
class MapScreen extends ConsumerStatefulWidget {
  const MapScreen({super.key});

  @override
  ConsumerState<MapScreen> createState() => _MapScreenState();
}

class _MapScreenState extends ConsumerState<MapScreen> {
  final _mapController = MapController();
  bool _followUser = true;

  @override
  void dispose() {
    _mapController.dispose();
    super.dispose();
  }

  Future<void> _centreOnUser() async {
    final position = await ref.read(devicePositionProvider.future);

    if (position == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('دسترسی به موقعیت مکانی فعال نیست.'),
          ),
        );
      }

      return;
    }

    setState(() => _followUser = true);
    _mapController.move(LatLng(position.latitude, position.longitude), 15);
  }

  @override
  Widget build(BuildContext context) {
    final mapConfig = ref.watch(mapConfigProvider);
    final buses = ref.watch(liveBusesProvider);
    final stops = ref.watch(nearbyStopsProvider);
    final position = ref.watch(devicePositionProvider).valueOrNull;

    return AppBackground(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        extendBody: true,
        body: SafeArea(
          bottom: false,
          child: Column(
            children: [
              _Header(busCount: buses.valueOrNull?.length ?? 0),

              Expanded(
                flex: 5,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
                  child: ClipRRect(
                    borderRadius: AppRadii.cardBorder,
                    child: Stack(
                      children: [
                        mapConfig.when(
                          loading: () => const Center(
                            child: CircularProgressIndicator(color: AppColors.brand400),
                          ),
                          error: (_, __) => const ErrorState(
                            message: 'نقشه در دسترس نیست.',
                            isOffline: true,
                          ),
                          data: (config) => FlutterMap(
                            mapController: _mapController,
                            options: MapOptions(
                              initialCenter: position != null
                                  ? LatLng(position.latitude, position.longitude)
                                  : LatLng(config.center.lat, config.center.lng),
                              initialZoom: config.zoom,
                              maxZoom: config.maxZoom.toDouble(),
                              minZoom: 10,
                              // Any manual pan means the user is looking at
                              // something; stop yanking the view back.
                              onPositionChanged: (_, hasGesture) {
                                if (hasGesture && _followUser) {
                                  setState(() => _followUser = false);
                                }
                              },
                            ),
                            children: [
                              TileLayer(
                                urlTemplate: config.tileUrl,
                                userAgentPackageName: 'com.hamsafar.passenger',
                                maxZoom: config.maxZoom.toDouble(),
                              ),
                              if (stops.hasValue)
                                MarkerLayer(
                                  markers: [
                                    for (final stop in stops.value!)
                                      Marker(
                                        point: LatLng(stop.position.lat, stop.position.lng),
                                        width: 26,
                                        height: 26,
                                        child: StopMarker(
                                          stop: stop,
                                          onTap: () {
                                            ref.read(selectedStopProvider.notifier).state = stop;
                                          },
                                        ),
                                      ),
                                  ],
                                ),
                              if (buses.hasValue)
                                MarkerLayer(
                                  markers: [
                                    for (final bus in buses.value!)
                                      Marker(
                                        point: LatLng(bus.position.lat, bus.position.lng),
                                        width: 44,
                                        height: 44,
                                        child: BusMarker(
                                          bus: bus,
                                          onTap: () => showTripSheet(context, bus),
                                        ),
                                      ),
                                  ],
                                ),
                              if (position != null)
                                MarkerLayer(
                                  markers: [
                                    Marker(
                                      point: LatLng(position.latitude, position.longitude),
                                      width: 22,
                                      height: 22,
                                      child: const _UserDot(),
                                    ),
                                  ],
                                ),
                              RichAttributionWidget(
                                attributions: [
                                  TextSourceAttribution(config.attribution, onTap: null),
                                ],
                              ),
                            ],
                          ),
                        ),

                        Positioned(
                          right: 12,
                          bottom: 12,
                          child: FloatingActionButton.small(
                            heroTag: 'locate',
                            backgroundColor: AppColors.ink850,
                            onPressed: _centreOnUser,
                            child: Icon(
                              _followUser ? Icons.my_location_rounded : Icons.location_searching_rounded,
                              color: AppColors.brand300,
                              size: 20,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),

              Expanded(flex: 4, child: _ArrivalBoard()),
            ],
          ),
        ),
      ),
    );
  }
}

class _Header extends ConsumerWidget {
  const _Header({required this.busCount});

  final int busCount;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.sm),
      child: GlassCard(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        child: Row(
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [AppColors.brand400, AppColors.brand600],
                ),
                borderRadius: BorderRadius.circular(12),
              ),
              child: const Icon(Icons.directions_bus_rounded, color: Colors.white, size: 20),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text('همسفر', style: Theme.of(context).textTheme.titleSmall),
                  Text('بندرعباس', style: Theme.of(context).textTheme.labelSmall),
                ],
              ),
            ),
            const LiveDot(),
            const SizedBox(width: 6),
            Text(
              '${Format.number(busCount)} اتوبوس',
              style: Theme.of(context).textTheme.labelMedium?.copyWith(color: AppColors.brand300),
            ),
            const SizedBox(width: 8),
            const SampleDataNotice(compact: true),
          ],
        ),
      ),
    );
  }
}

class _ArrivalBoard extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final arrivals = ref.watch(arrivalsProvider);
    final selectedStop = ref.watch(selectedStopProvider);
    final nearby = ref.watch(nearbyStopsProvider);

    final stop = selectedStop ?? nearby.valueOrNull?.firstOrNull;

    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.md, AppSpacing.md, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  stop == null ? 'ایستگاه‌های نزدیک' : 'ایستگاه ${stop.name}',
                  style: Theme.of(context).textTheme.titleSmall,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              if (stop?.distanceMeters != null)
                Text(
                  Format.distance(stop!.distanceMeters),
                  style: Theme.of(context).textTheme.labelSmall,
                ),
              IconButton(
                onPressed: () => ref.invalidate(arrivalsProvider),
                icon: const Icon(Icons.refresh_rounded, size: 18),
                color: AppColors.brand300,
                visualDensity: VisualDensity.compact,
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),

          Expanded(
            child: arrivals.when(
              loading: () => ListView.separated(
                itemCount: 3,
                separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
                itemBuilder: (_, __) => const ShimmerBox(height: 68),
              ),
              error: (error, _) => ErrorState(
                message: error is ApiException ? error.message : 'دریافت اطلاعات ممکن نشد.',
                isOffline: error is NetworkException,
                onRetry: () => ref.invalidate(arrivalsProvider),
              ),
              data: (items) => items.isEmpty
                  ? const EmptyState(
                      icon: Icons.schedule_rounded,
                      message: 'در حال حاضر اتوبوسی به این ایستگاه نزدیک نیست.',
                    )
                  : ListView.separated(
                      padding: const EdgeInsets.only(bottom: 90),
                      itemCount: items.length,
                      separatorBuilder: (_, __) => const SizedBox(height: AppSpacing.sm),
                      itemBuilder: (_, index) => ArrivalTile(arrival: items[index]),
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class _UserDot extends StatelessWidget {
  const _UserDot();

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: AppColors.info,
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white, width: 3),
        boxShadow: [
          BoxShadow(color: AppColors.info.withValues(alpha: 0.35), blurRadius: 12, spreadRadius: 4),
        ],
      ),
    );
  }
}
