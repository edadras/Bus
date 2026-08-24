<?php

namespace Tests\Feature\SchoolTransport;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\SchoolTransport\Enums\SchoolInvoiceStatus;
use App\Domain\SchoolTransport\Enums\SchoolTripStatus;
use App\Domain\SchoolTransport\Models\School;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Domain\SchoolTransport\Models\SchoolServiceRoute;
use App\Domain\SchoolTransport\Models\SchoolStudent;
use App\Domain\SchoolTransport\Models\SchoolTrip;
use App\Domain\SchoolTransport\Models\SchoolVehicle;
use App\Domain\SchoolTransport\Services\SchoolContractService;
use App\Domain\SchoolTransport\Services\SchoolTripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unattended half of the school service.
 *
 * Nobody clicks a button at two in the morning, so these are the three things
 * that have to happen on their own: the day gets built before it starts, a run
 * nobody closed stops sharing a van's position, and a family is billed once per
 * period — once, however many times the scheduler runs.
 */
class ScheduledCommandsTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private SchoolCompany $company;

    private SchoolServiceRoute $route;

    private SchoolStudent $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();

        $school = School::factory()->create(['city_id' => $this->city->id]);

        $this->company = SchoolCompany::factory()->approved()->create([
            'city_id' => $this->city->id,
            'owner_user_id' => User::factory()->create(['city_id' => $this->city->id])->id,
        ]);

        $this->route = SchoolServiceRoute::factory()->create([
            'school_company_id' => $this->company->id,
            'school_id' => $school->id,
            'city_id' => $this->city->id,
            'school_vehicle_id' => SchoolVehicle::factory()->create([
                'school_company_id' => $this->company->id,
                'city_id' => $this->city->id,
            ])->id,
            'driver_id' => Driver::factory()->create(['city_id' => $this->city->id])->id,
        ]);

        $guardian = $this->makePassenger($this->city);

        $this->student = SchoolStudent::factory()->create([
            'guardian_user_id' => $guardian->id,
            'city_id' => $this->city->id,
            'school_id' => $school->id,
        ]);

        $this->activeContract();
    }

    private function activeContract(): SchoolServiceContract
    {
        $contracts = app(SchoolContractService::class);

        $contract = $contracts->accept(
            $contracts->request($this->student, $this->company, $this->student->guardian, [
                'starts_on' => today()->subDay()->toDateString(),
            ]),
            $this->company->owner,
            1_000_000,
        );

        return $contracts->assignRoute($contract, $this->route, $this->company->owner);
    }

    // ── building the day ────────────────────────────────────────────────────

    public function test_the_day_is_built_before_it_starts(): void
    {
        $this->artisan('school:runs:schedule', ['--days' => 1])->assertSuccessful();

        // Today and tomorrow, each way.
        $this->assertSame(4, SchoolTrip::count());
        $this->assertSame(2, SchoolTrip::whereDate('service_date', today())->count());
        $this->assertSame(2, SchoolTrip::whereDate('service_date', today()->addDay())->count());
    }

    public function test_running_it_twice_does_not_duplicate_the_day(): void
    {
        $this->artisan('school:runs:schedule', ['--days' => 0])->assertSuccessful();
        $this->artisan('school:runs:schedule', ['--days' => 0])->assertSuccessful();

        $this->assertSame(2, SchoolTrip::count());
    }

    public function test_a_route_missing_its_van_is_not_scheduled(): void
    {
        // An empty manifest in front of nobody is worse than no run at all: it
        // makes the company's board look staffed when it is not.
        $this->route->forceFill(['school_vehicle_id' => null])->save();

        $this->artisan('school:runs:schedule', ['--days' => 0])->assertSuccessful();

        $this->assertSame(0, SchoolTrip::count());
    }

    public function test_an_inactive_route_is_not_scheduled(): void
    {
        $this->route->forceFill(['is_active' => false])->save();

        $this->artisan('school:runs:schedule', ['--days' => 0])->assertSuccessful();

        $this->assertSame(0, SchoolTrip::count());
    }

    // ── closing what nobody closed ──────────────────────────────────────────

    public function test_a_run_left_open_stops_sharing_the_van(): void
    {
        $trips = app(SchoolTripService::class);

        $run = $trips->scheduleFor($this->route, today())[0];
        $trips->start($run, $this->route->driver);

        $run->forceFill([
            'started_at' => now()->subHours((int) config('school.trips.auto_close_after_hours') + 1),
        ])->save();

        $this->artisan('school:runs:close-stale')->assertSuccessful();

        $this->assertSame(SchoolTripStatus::Completed, $run->fresh()->status);
    }

    public function test_a_run_that_started_an_hour_ago_is_left_alone(): void
    {
        $trips = app(SchoolTripService::class);

        $run = $trips->scheduleFor($this->route, today())[0];
        $trips->start($run, $this->route->driver);

        $run->forceFill(['started_at' => now()->subHour()])->save();

        $this->artisan('school:runs:close-stale')->assertSuccessful();

        $this->assertSame(SchoolTripStatus::InProgress, $run->fresh()->status);
    }

    // ── billing ─────────────────────────────────────────────────────────────

    public function test_an_active_contract_is_billed_for_the_period(): void
    {
        $this->artisan('school:contracts:bill')->assertSuccessful();

        $invoice = SchoolServiceContract::firstOrFail()->invoices()->firstOrFail();

        $this->assertSame(1_000_000, $invoice->amount);
        $this->assertSame(SchoolInvoiceStatus::Pending, $invoice->status);
    }

    public function test_a_family_is_billed_once_however_often_the_scheduler_runs(): void
    {
        $this->artisan('school:contracts:bill')->assertSuccessful();
        $this->artisan('school:contracts:bill')->assertSuccessful();
        $this->artisan('school:contracts:bill')->assertSuccessful();

        $this->assertSame(1, SchoolServiceContract::firstOrFail()->invoices()->count());
    }

    public function test_a_contract_with_no_fee_agreed_yet_is_not_billed(): void
    {
        SchoolServiceContract::query()->update(['fee_amount' => 0]);

        $this->artisan('school:contracts:bill')->assertSuccessful();

        $this->assertSame(0, SchoolServiceContract::firstOrFail()->invoices()->count());
    }

    public function test_an_invoice_past_its_due_date_becomes_overdue(): void
    {
        $this->artisan('school:contracts:bill')->assertSuccessful();

        $invoice = SchoolServiceContract::firstOrFail()->invoices()->firstOrFail();
        $invoice->forceFill(['due_on' => today()->subDay()])->save();

        $this->artisan('school:contracts:bill')->assertSuccessful();

        $this->assertSame(SchoolInvoiceStatus::Overdue, $invoice->fresh()->status);
    }
}
