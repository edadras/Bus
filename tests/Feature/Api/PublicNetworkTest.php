<?php

namespace Tests\Feature\Api;

use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use App\Domain\Network\Models\City;
use App\Domain\Operations\Services\RouteMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guests must be able to plan a journey without an account, and must always be
 * told when the data they are looking at is not the official network.
 */
class PublicNetworkTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private BusLine $line;

    private BusRoute $route;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->line = BusLine::factory()->create(['city_id' => $this->city->id, 'code' => '102']);
        $this->route = BusRoute::factory()->create(['bus_line_id' => $this->line->id]);

        foreach (range(0, 3) as $index) {
            $stop = BusStop::factory()->at(27.180 + $index * 0.006, 56.260)->create([
                'city_id' => $this->city->id,
            ]);

            $this->route->routeStops()->create([
                'bus_stop_id' => $stop->id,
                'sequence' => $index + 1,
            ]);
        }

        app(RouteMatcher::class)->recalculateStopOffsets($this->route);
    }

    public function test_a_guest_can_list_stops(): void
    {
        $this->getJson('/api/v1/stops')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.provenance', 'sample');
    }

    public function test_stops_are_labelled_with_their_provenance(): void
    {
        // Nothing may present sample geometry as verified official data.
        $this->getJson('/api/v1/stops')
            ->assertOk()
            ->assertJsonPath('data.0.is_verified_data', false);

        BusStop::query()->update(['provenance' => NetworkProvenance::Official->value]);

        $this->getJson('/api/v1/stops')
            ->assertOk()
            ->assertJsonPath('data.0.is_verified_data', true);
    }

    public function test_nearby_search_orders_by_true_distance(): void
    {
        $response = $this->getJson('/api/v1/stops?lat=27.1800&lng=56.2600&radius=2000')->assertOk();

        $distances = array_column($response->json('data'), 'distance_meters');

        $this->assertSame($distances, array_values(collect($distances)->sort()->all()));
        $this->assertSame(0, $distances[0]);
    }

    public function test_nearby_search_excludes_stops_beyond_the_radius(): void
    {
        // Stops sit 0.006 degrees of latitude apart, i.e. about 666 m, so a
        // 500 m radius reaches only the first and a 1.5 km radius reaches three.
        $this->getJson('/api/v1/stops?lat=27.1800&lng=56.2600&radius=500')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/stops?lat=27.1800&lng=56.2600&radius=1500')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_a_guest_can_view_a_line_and_its_routes(): void
    {
        $this->getJson('/api/v1/lines')
            ->assertOk()
            ->assertJsonPath('data.0.code', '102');

        $this->getJson('/api/v1/lines/'.$this->line->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.routes');
    }

    public function test_route_geometry_is_only_sent_when_requested(): void
    {
        $this->getJson('/api/v1/routes/'.$this->route->id)
            ->assertOk()
            ->assertJsonMissingPath('data.geometry');

        $this->getJson('/api/v1/routes/'.$this->route->id.'?with_geometry=1')
            ->assertOk()
            ->assertJsonStructure(['data' => ['geometry']]);
    }

    public function test_an_arrival_board_is_returned_for_a_stop(): void
    {
        $stop = BusStop::first();

        $this->getJson("/api/v1/stops/{$stop->id}/arrivals")
            ->assertOk()
            // No buses are running, so the board is empty rather than absent.
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.stop.id', $stop->id);
    }

    public function test_the_journey_planner_finds_a_direct_line(): void
    {
        $first = BusStop::orderBy('id')->first();
        $last = BusStop::orderByDesc('id')->first();

        $response = $this->getJson(sprintf(
            '/api/v1/journey/plan?from_lat=%s&from_lng=%s&to_lat=%s&to_lng=%s',
            $first->lat, $first->lng, $last->lat, $last->lng,
        ))->assertOk();

        $this->assertCount(1, $response->json('data.options'));
        $this->assertSame('102', $response->json('data.options.0.line.code'));
        $this->assertSame(3, $response->json('data.options.0.stops_count'));
    }

    public function test_the_planner_reports_honestly_when_no_line_serves_the_journey(): void
    {
        $response = $this->getJson(
            '/api/v1/journey/plan?from_lat=27.1800&from_lng=56.2600&to_lat=27.9000&to_lng=56.9000'
        )->assertOk();

        $this->assertSame([], $response->json('data.options'));
        $this->assertSame('no_stop_within_walking_distance', $response->json('data.reason'));
        // The planner searches transfers, so an empty answer means there is
        // genuinely no journey — not that it stopped looking.
        $this->assertTrue($response->json('data.supports_transfers'));
        $this->assertSame(2, $response->json('data.max_transfers'));
    }

    public function test_map_configuration_is_served_without_a_hard_coded_provider(): void
    {
        $this->getJson('/api/v1/map/config')
            ->assertOk()
            ->assertJsonPath('data.provider.provider', 'osm')
            ->assertJsonStructure(['data' => ['provider' => ['tile_url', 'attribution', 'max_zoom'], 'center', 'zoom']]);
    }

    public function test_map_configuration_names_its_city_so_a_guest_can_find_the_live_feed(): void
    {
        // The live bus channel is named after the city, and the map is
        // reachable without an account — so a signed-out client has to learn
        // the city from somewhere it can already reach.
        $this->getJson('/api/v1/map/config')
            ->assertOk()
            ->assertJsonPath('data.city.id', $this->city->id)
            ->assertJsonPath('data.city.slug', $this->city->slug);
    }

    public function test_another_citys_stop_is_not_reachable(): void
    {
        $otherCity = City::factory()->create(['slug' => 'shiraz']);
        $foreignStop = BusStop::factory()->create(['city_id' => $otherCity->id]);

        $this->getJson('/api/v1/stops/'.$foreignStop->id)->assertStatus(404);
    }
}
