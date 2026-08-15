import 'package:flutter_test/flutter_test.dart';
import 'package:hamsafar_core/hamsafar_core.dart';

void main() {
  group('LiveBus', () {
    test('parses a live payload', () {
      final bus = LiveBus.fromJson({
        'trip_uuid': 'abc',
        'lat': 27.18,
        'lng': 56.26,
        'bus_number': 102,
        'line_code': '102',
        'passenger_count': 27,
        'occupancy': 0.54,
        'heading': 91.5,
        'updated_at': DateTime.now().toIso8601String(),
      });

      expect(bus.tripUuid, 'abc');
      expect(bus.passengerCount, 27);
      // Numeric fields arrive as ints or strings depending on the driver app.
      expect(bus.busNumber, '102');
      expect(bus.isStale, isFalse);
    });

    test('marks a bus stale once its last ping is old', () {
      final bus = LiveBus.fromJson({
        'trip_uuid': 'abc',
        'lat': 27.18,
        'lng': 56.26,
        'updated_at': DateTime.now().subtract(const Duration(minutes: 5)).toIso8601String(),
      });

      expect(bus.isStale, isTrue);
    });

    test('treats a missing timestamp as stale rather than fresh', () {
      final bus = LiveBus.fromJson({'trip_uuid': 'abc', 'lat': 27.0, 'lng': 56.0});

      expect(bus.isStale, isTrue);
    });
  });

  group('Arrival', () {
    test('surfaces the server-supplied reliability flag', () {
      final reliable = Arrival.fromJson({
        'trip_uuid': 'a',
        'eta': {'seconds': 240, 'minutes': 4, 'confidence': 0.82, 'reliable': true},
      });

      final unreliable = Arrival.fromJson({
        'trip_uuid': 'b',
        'eta': {'seconds': 900, 'minutes': 15, 'confidence': 0.3, 'reliable': false},
      });

      expect(reliable.isReliable, isTrue);
      expect(unreliable.isReliable, isFalse);
    });

    test('survives a malformed eta block', () {
      final arrival = Arrival.fromJson({'trip_uuid': 'a'});

      expect(arrival.etaSeconds, 0);
      expect(arrival.isReliable, isFalse);
    });
  });

  group('BusStop', () {
    test('reports unverified provenance so the UI can label it', () {
      final sample = BusStop.fromJson({
        'id': 1,
        'code': 'A',
        'name': 'ایستگاه',
        'lat': 27.1,
        'lng': 56.2,
        'is_verified_data': false,
      });

      expect(sample.isVerifiedData, isFalse);
    });
  });

  group('Wallet', () {
    test('parses a balance and spendability', () {
      final wallet = Wallet.fromJson({
        'balance': 500000,
        'formatted_balance': '۵۰٬۰۰۰ تومان',
        'status_label': 'فعال',
        'can_spend': true,
      });

      expect(wallet.balance, 500000);
      expect(wallet.canSpend, isTrue);
    });

    test('a frozen wallet cannot spend', () {
      final wallet = Wallet.fromJson({
        'balance': 500000,
        'status_label': 'مسدود',
        'can_spend': false,
      });

      expect(wallet.canSpend, isFalse);
    });
  });

  group('MapConfig', () {
    test('falls back to a usable provider when the payload is empty', () {
      final config = MapConfig.fromJson(const {});

      expect(config.tileUrl, isNotEmpty);
      expect(config.center.lat, isNot(0));
    });
  });

  group('AppNotification', () {
    test('parses an inbox entry', () {
      final notification = AppNotification.fromJson(const {
        'id': '9c1e-uuid',
        'type': 'bus_approaching',
        'title': 'اتوبوس نزدیک است',
        'body': 'خط ۱ تا ۳ دقیقه دیگر می‌رسد.',
        'data': {'line_code': '1', 'minutes': 3},
        'read': false,
        'created_at': '2026-08-15T08:30:00+00:00',
      });

      expect(notification.id, '9c1e-uuid');
      expect(notification.type, 'bus_approaching');
      expect(notification.read, isFalse);
      expect(notification.data['minutes'], 3);
      expect(notification.createdAt, isNotNull);
    });

    test('an unknown payload still renders rather than throwing', () {
      final notification = AppNotification.fromJson(const {'id': 'x'});

      // A future notification type must never crash an installed app.
      expect(notification.type, 'general');
      expect(notification.title, isNull);
      expect(notification.read, isFalse);
    });

    test('marking read produces a new value rather than mutating', () {
      const original = AppNotification(id: 'x', type: 'general', read: false);

      expect(original.copyWith(read: true).read, isTrue);
      expect(original.read, isFalse);
    });
  });

  group('JourneyPlan', () {
    test('parses a two-leg itinerary the way the planner emits it', () {
      final plan = JourneyPlan.fromJson(const {
        'options': [
          {
            'mode': 'bus',
            'transfers': 1,
            'estimated_total_minutes': 34,
            'walk_minutes': 9,
            'total_walk_meters': 720,
            'walk_to_stop_meters': 240,
            'walk_from_stop_meters': 180,
            'stops_count': 14,
            'ride_distance_meters': 8400,
            'legs': [
              {
                'line': {'id': 1, 'code': '1', 'name': 'گلشهر — رسالت', 'color': '#12B76A'},
                'board_at': {'id': 10, 'name': 'میدان شهدا'},
                'alight_at': {'id': 18, 'name': 'چهارراه فاطمیه'},
                'stops_count': 8,
                'ride_minutes': 16,
                'headway_minutes': 12,
              },
              {
                'line': {'id': 4, 'code': '4', 'name': 'رسالت — بندر', 'color': null},
                'board_at': {'id': 18, 'name': 'چهارراه فاطمیه'},
                'alight_at': {'id': 25, 'name': 'اسکله شهید حقانی'},
                'stops_count': 6,
                'ride_minutes': 9,
                'headway_minutes': 15,
              },
            ],
          },
        ],
      });

      expect(plan.reason, isNull);
      expect(plan.options, hasLength(1));

      final option = plan.options.single;

      expect(option.isWalk, isFalse);
      expect(option.transfers, 1);
      expect(option.totalMinutes, 34);
      // The two ends of the walk are kept apart from the total, because the
      // itinerary says where the walking happens.
      expect(option.walkToStopMeters, 240);
      expect(option.walkFromStopMeters, 180);
      expect(option.legs, hasLength(2));
      expect(option.legs.first.boardStopName, 'میدان شهدا');
      expect(option.legs.last.alightStopName, 'اسکله شهید حقانی');
      expect(option.legs.last.lineColor, isNull);
    });

    test('carries the reason when there is nothing to offer', () {
      final plan = JourneyPlan.fromJson(const {
        'options': <Map<String, dynamic>>[],
        'reason': 'no_stop_within_walking_distance',
      });

      // Without this the screen could only show a blank list, and "no stop
      // near you" and "no route exists" call for different actions.
      expect(plan.options, isEmpty);
      expect(plan.reason, 'no_stop_within_walking_distance');
    });

    test('a walk-only answer has no legs and is flagged as walking', () {
      final plan = JourneyPlan.fromJson(const {
        'options': [
          {
            'mode': 'walk',
            'transfers': 0,
            'estimated_total_minutes': 7,
            'walk_minutes': 7,
            'total_walk_meters': 560,
            'legs': <Map<String, dynamic>>[],
          },
        ],
      });

      expect(plan.options.single.isWalk, isTrue);
      expect(plan.options.single.legs, isEmpty);
    });

    test('a line carries the routes it runs, in both directions', () {
      final detail = LineDetail.fromJson(const {
        'id': 7,
        'code': '4',
        'name': 'رسالت — بندر',
        'color': '#12B76A',
        'routes': [
          {
            'id': 11,
            'name': 'رفت',
            'direction_label': 'رفت',
            'distance_meters': 8400,
            'origin': {'name': 'میدان شهدا'},
            'destination': {'name': 'اسکله'},
          },
          {'id': 12, 'name': 'برگشت', 'direction_label': 'برگشت'},
        ],
      });

      // The two directions are separate routes, not one reversed: a passenger
      // reading the wrong one is reading a different set of stops.
      expect(detail.line.code, '4');
      expect(detail.routes, hasLength(2));
      expect(detail.routes.first.originName, 'میدان شهدا');
      expect(detail.routes.last.distanceMeters, isNull);
    });

    test('a route reports its stops in order with their offsets', () {
      final route = RouteDetail.fromJson(const {
        'id': 11,
        'name': 'رفت',
        'direction_label': 'رفت',
        'distance_meters': 8400,
        'stops': [
          {
            'sequence': 1,
            'distance_from_start': 0,
            'stop': {'id': 1, 'name': 'میدان شهدا', 'lat': 27.1, 'lng': 56.2},
          },
          {
            'sequence': 2,
            'distance_from_start': 1200,
            'is_timepoint': true,
            'stop': {'id': 2, 'name': 'چهارراه فاطمیه', 'lat': 27.1, 'lng': 56.21},
          },
        ],
      });

      expect(route.stops.map((item) => item.sequence), [1, 2]);
      expect(route.stops.last.isTimepoint, isTrue);
      expect(route.stops.last.distanceFromStart, 1200);
    });

    test('a malformed option degrades instead of throwing', () {
      final plan = JourneyPlan.fromJson(const {
        'options': [
          {'legs': null},
        ],
      });

      expect(plan.options.single.mode, 'bus');
      expect(plan.options.single.totalMinutes, 0);
      expect(plan.options.single.legs, isEmpty);
    });
  });
}
