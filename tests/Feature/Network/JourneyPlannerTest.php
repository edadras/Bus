<?php

namespace Tests\Feature\Network;

use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use App\Domain\Network\Models\City;
use App\Domain\Network\Services\JourneyPlanner;
use App\Domain\Operations\Services\RouteMatcher;
use App\Support\Geo\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The planner is tested against a network shaped like a real one: two lines
 * that cross, and a third that shares no stop with either but has a stop
 * across the road from one.
 *
 *   Line A  a1 ── a2 ── X ── a4          (west → east, through X)
 *   Line B            X ── b2 ── b3      (X → north)
 *   Line C       c1 ── c2 ── c3          (c1 is 120 m from a4: a walking change)
 *
 * A journey from a1 to b3 is only possible by changing at X, which is the case
 * the first release could not answer at all.
 */
class JourneyPlannerTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private JourneyPlanner $planner;

    /** @var array<string, BusStop> */
    private array $stops = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->planner = app(JourneyPlanner::class);

        // ~0.009° of latitude is roughly a kilometre, comfortably outside the
        // walking radius, so every hop below must really be ridden.
        $this->stop('a1', 27.180, 56.260);
        $this->stop('a2', 27.180, 56.270);
        $this->stop('x', 27.180, 56.280);
        $this->stop('a4', 27.180, 56.290);

        $this->stop('b2', 27.190, 56.280);
        $this->stop('b3', 27.200, 56.280);

        $this->stop('c1', 27.1801, 56.29013);  // ~120 m from a4
        $this->stop('c2', 27.170, 56.290);
        $this->stop('c3', 27.160, 56.290);

        $this->line('A', ['a1', 'a2', 'x', 'a4'], headway: 10);
        $this->line('B', ['x', 'b2', 'b3'], headway: 10);
        $this->line('C', ['c1', 'c2', 'c3'], headway: 10);
    }

    private function stop(string $key, float $lat, float $lng): void
    {
        $this->stops[$key] = BusStop::factory()->at($lat, $lng)->create([
            'city_id' => $this->city->id,
            'code' => strtoupper($key),
            'name' => 'ایستگاه '.$key,
        ]);
    }

    /** @param array<int, string> $stopKeys */
    private function line(string $code, array $stopKeys, int $headway): BusRoute
    {
        $line = BusLine::factory()->create([
            'city_id' => $this->city->id,
            'code' => $code,
            'headway_minutes' => $headway,
        ]);

        $route = BusRoute::factory()->create(['bus_line_id' => $line->id]);

        foreach ($stopKeys as $index => $key) {
            $route->routeStops()->create([
                'bus_stop_id' => $this->stops[$key]->id,
                'sequence' => $index + 1,
            ]);
        }

        app(RouteMatcher::class)->recalculateStopOffsets($route);

        return $route;
    }

    private function planBetween(string $fromKey, string $toKey, int $maxTransfers = 2): array
    {
        return $this->planner->plan(
            city: $this->city,
            from: new Coordinate((float) $this->stops[$fromKey]->lat, (float) $this->stops[$fromKey]->lng),
            to: new Coordinate((float) $this->stops[$toKey]->lat, (float) $this->stops[$toKey]->lng),
            maxTransfers: $maxTransfers,
        );
    }

    private function busOptions(array $result): array
    {
        return array_values(array_filter($result['options'], fn ($o) => $o['mode'] === 'bus'));
    }

    public function test_a_direct_journey_needs_no_transfer(): void
    {
        $best = $this->busOptions($this->planBetween('a1', 'a4'))[0];

        $this->assertSame(0, $best['transfers']);
        $this->assertSame('A', $best['line']['code']);
        $this->assertSame(3, $best['stops_count']);
        $this->assertCount(1, $best['legs']);
    }

    public function test_a_journey_that_requires_changing_bus_is_found(): void
    {
        $result = $this->planBetween('a1', 'b3');
        $best = $this->busOptions($result)[0];

        $this->assertTrue($result['supports_transfers']);
        $this->assertSame(1, $best['transfers']);
        $this->assertCount(2, $best['legs']);

        $this->assertSame('A', $best['legs'][0]['line']['code']);
        $this->assertSame('B', $best['legs'][1]['line']['code']);

        // The change happens at the stop the two lines share.
        $this->assertSame($this->stops['x']->id, $best['legs'][0]['alight_at']['id']);
        $this->assertSame($this->stops['x']->id, $best['legs'][1]['board_at']['id']);
    }

    public function test_a_transfer_can_be_a_short_walk_between_two_stops(): void
    {
        // a4 and c1 are 120 m apart and share no route: the interchange only
        // exists if the planner is willing to walk between stops.
        $best = $this->busOptions($this->planBetween('a1', 'c3'))[0];

        $this->assertSame(1, $best['transfers']);
        $this->assertSame('A', $best['legs'][0]['line']['code']);
        $this->assertSame('C', $best['legs'][1]['line']['code']);
        $this->assertGreaterThan(0, $best['transfer_walk_meters']);
        $this->assertLessThan(300, $best['transfer_walk_meters']);
    }

    public function test_capping_transfers_at_zero_reports_no_route_rather_than_inventing_one(): void
    {
        $result = $this->planBetween('a1', 'b3', maxTransfers: 0);

        $this->assertSame([], $this->busOptions($result));
        $this->assertSame('no_route_found', $result['reason']);
        $this->assertSame(0, $result['max_transfers']);
    }

    public function test_the_wait_for_a_second_bus_is_part_of_the_estimate(): void
    {
        // Every leg carries the expected wait — half the headway — so a
        // two-bus journey is never scored as though the change were free.
        $best = $this->busOptions($this->planBetween('a1', 'b3'))[0];

        $ridesOnly = array_sum(array_column($best['legs'], 'ride_minutes'));

        $this->assertGreaterThanOrEqual(count($best['legs']) * 5, $ridesOnly);
        $this->assertGreaterThan($ridesOnly, $best['estimated_total_minutes']);
    }

    public function test_a_slower_line_does_not_displace_a_faster_one(): void
    {
        $options = $this->busOptions($this->planBetween('a1', 'b3'));

        $minutes = array_column($options, 'estimated_total_minutes');
        $sorted = $minutes;
        sort($sorted);

        $this->assertSame($sorted, $minutes, 'Options must be ordered by total time.');
    }

    public function test_one_option_per_line_combination(): void
    {
        $options = $this->busOptions($this->planBetween('a1', 'b3'));

        $signatures = array_map(
            fn ($option) => implode('>', array_column(array_column($option['legs'], 'line'), 'code')),
            $options,
        );

        $this->assertSame(array_unique($signatures), $signatures);
    }

    public function test_walking_is_offered_when_it_beats_waiting_for_a_bus(): void
    {
        // Two stops 120 m apart: no sane planner tells you to wait for a bus.
        $result = $this->planner->plan(
            city: $this->city,
            from: new Coordinate((float) $this->stops['a4']->lat, (float) $this->stops['a4']->lng),
            to: new Coordinate((float) $this->stops['c1']->lat, (float) $this->stops['c1']->lng),
        );

        $this->assertSame('walk', $result['options'][0]['mode']);
        $this->assertLessThan(300, $result['options'][0]['total_walk_meters']);
    }

    public function test_a_journey_with_no_stop_at_either_end_still_suggests_walking_when_it_is_short(): void
    {
        // Far from the network, but the two points are 100 m apart.
        $result = $this->planner->plan(
            city: $this->city,
            from: new Coordinate(27.900, 56.900),
            to: new Coordinate(27.9009, 56.900),
        );

        $this->assertSame('walk', $result['options'][0]['mode']);
    }

    public function test_a_journey_far_from_the_network_reports_why(): void
    {
        $result = $this->planner->plan(
            city: $this->city,
            from: new Coordinate(27.900, 56.900),
            to: new Coordinate(28.400, 57.400),
        );

        $this->assertSame([], $result['options']);
        $this->assertSame('no_stop_within_walking_distance', $result['reason']);
    }

    public function test_the_planner_never_rides_the_same_line_twice_in_a_row(): void
    {
        foreach ($this->busOptions($this->planBetween('a1', 'c3')) as $option) {
            $codes = array_column(array_column($option['legs'], 'line'), 'code');

            for ($i = 1; $i < count($codes); $i++) {
                $this->assertNotSame(
                    $codes[$i - 1],
                    $codes[$i],
                    'Changing onto the line you just left is not a transfer.',
                );
            }
        }
    }

    public function test_an_inactive_line_is_not_planned_onto(): void
    {
        BusLine::where('code', 'B')->update(['is_active' => false]);

        $result = $this->planBetween('a1', 'b3');

        $this->assertSame([], $this->busOptions($result));
    }

    public function test_a_stop_that_does_not_allow_boarding_is_not_used_as_an_origin(): void
    {
        $line = BusLine::where('code', 'B')->firstOrFail();
        $route = BusRoute::where('bus_line_id', $line->id)->firstOrFail();

        $route->routeStops()
            ->where('bus_stop_id', $this->stops['x']->id)
            ->update(['allows_boarding' => false]);

        // X is the only interchange between A and B, so refusing boardings
        // there must remove the journey rather than quietly ignore the flag.
        $this->assertSame([], $this->busOptions($this->planBetween('a1', 'b3')));
    }
}
