<?php

namespace Tests\Feature\Network;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use App\Domain\Network\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing the network from the panel.
 *
 * The property under test throughout is that stop offsets stay honest. Every
 * arrival estimate and every "next stop" is computed from a stop's distance
 * along its route, so creating a route, moving a stop, or importing new
 * geometry all have to leave those offsets rebuilt — a route saved with stale
 * offsets is a route that quietly lies to passengers.
 */
class NetworkAdminTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private BusLine $line;

    /** @var array<int, BusStop> */
    private array $stops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]));

        $this->line = BusLine::factory()->create(['city_id' => $this->city->id]);

        // Three stops on a straight east-west line, roughly a kilometre apart.
        $this->stops = collect([0, 0.01, 0.02])
            ->map(fn (float $offset, int $index) => BusStop::factory()->create([
                'city_id' => $this->city->id,
                'code' => 'S'.$index,
                'lat' => 27.1832,
                'lng' => 56.2666 + $offset,
            ]))
            ->all();
    }

    private function createRoute(?array $stops = null): array
    {
        $stops ??= array_map(fn (BusStop $stop) => ['bus_stop_id' => $stop->id], $this->stops);

        return $this->postJson("/api/v1/admin/network/lines/{$this->line->id}/routes", [
            'name' => 'مسیر رفت',
            'direction' => 'outbound',
            'is_default' => true,
            'geometry' => [
                ['lat' => 27.1832, 'lng' => 56.2666],
                ['lat' => 27.1832, 'lng' => 56.2866],
            ],
            'stops' => $stops,
        ])->assertCreated()->json('data');
    }

    public function test_creating_a_route_numbers_its_stops_and_measures_them(): void
    {
        $route = $this->createRoute();

        $sequences = array_column($route['stops'], 'sequence');
        $offsets = array_column($route['stops'], 'distance_from_start');

        $this->assertSame([1, 2, 3], $sequences);
        // Offsets are computed in the same transaction, so a route is never
        // left with a sequence the ETA maths cannot use.
        $this->assertSame($offsets, array_values(array_filter($offsets, 'is_int')));
        $this->assertGreaterThan($offsets[0], $offsets[2]);
    }

    public function test_the_first_and_last_stop_become_the_routes_ends(): void
    {
        $route = $this->createRoute();

        $this->assertSame($this->stops[0]->id, $route['origin']['id']);
        $this->assertSame($this->stops[2]->id, $route['destination']['id']);
    }

    public function test_a_route_of_one_stop_is_refused(): void
    {
        $this->postJson("/api/v1/admin/network/lines/{$this->line->id}/routes", [
            'name' => 'ناقص',
            'direction' => 'outbound',
            'stops' => [['bus_stop_id' => $this->stops[0]->id]],
        ])->assertStatus(422);
    }

    public function test_moving_a_stop_rebuilds_the_offsets_of_every_route_it_is_on(): void
    {
        $this->createRoute();

        $middle = BusRoute::firstOrFail()->routeStops()->where('sequence', 2)->firstOrFail();
        $before = $middle->distance_from_start;

        // Push the middle stop most of the way to the far end of the line.
        $this->patchJson("/api/v1/admin/network/stops/{$this->stops[1]->id}", [
            'lng' => 56.2846,
        ])->assertOk();

        // Nothing else changed, so an unchanged offset here means the offsets
        // were never rebuilt and every ETA past this stop is now wrong.
        $this->assertNotSame($before, $middle->fresh()->distance_from_start);
    }

    public function test_recalculating_reports_how_many_stops_it_touched(): void
    {
        $this->createRoute();

        $this->postJson('/api/v1/admin/network/routes/'.BusRoute::firstOrFail()->id.'/recalculate')
            ->assertOk()
            ->assertJsonPath('data.stops_updated', 3);
    }

    public function test_a_stop_edit_is_audited(): void
    {
        $this->patchJson("/api/v1/admin/network/stops/{$this->stops[0]->id}", ['name' => 'میدان جدید'])
            ->assertOk()
            ->assertJsonPath('data.name', 'میدان جدید');

        $this->assertDatabaseHas('audit_logs', ['action' => 'network.stop.updated']);
    }

    public function test_another_citys_stop_and_route_are_out_of_reach(): void
    {
        $other = City::factory()->create(['slug' => 'shiraz']);
        $stranger = BusStop::factory()->create(['city_id' => $other->id]);
        $strangerLine = BusLine::factory()->create(['city_id' => $other->id]);

        $this->patchJson("/api/v1/admin/network/stops/{$stranger->id}", ['name' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/admin/network/lines/{$strangerLine->id}/routes", [
            'name' => 'x',
            'direction' => 'outbound',
            'stops' => [['bus_stop_id' => $stranger->id], ['bus_stop_id' => $stranger->id]],
        ])->assertNotFound();
    }

    public function test_network_editing_is_closed_to_a_fleet_manager(): void
    {
        $this->actingAsAdmin(
            User::factory()->create(['city_id' => $this->city->id]),
            Role::FLEET_MANAGER,
        );

        $this->patchJson("/api/v1/admin/network/stops/{$this->stops[0]->id}", ['name' => 'x'])
            ->assertForbidden();
    }
}
