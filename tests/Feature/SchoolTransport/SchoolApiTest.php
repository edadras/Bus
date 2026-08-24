<?php

namespace Tests\Feature\SchoolTransport;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\SchoolTransport\Models\School;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Domain\SchoolTransport\Models\SchoolServiceRoute;
use App\Domain\SchoolTransport\Models\SchoolStudent;
use App\Domain\SchoolTransport\Models\SchoolVehicle;
use App\Domain\SchoolTransport\Services\SchoolContractService;
use App\Domain\SchoolTransport\Services\SchoolTripService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The school transport endpoints as the three apps drive them.
 *
 * The line held here is the one between a city administrator and a company
 * manager: they share endpoints, and the company manager must never see another
 * company's children.
 */
class SchoolApiTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private SchoolCompany $company;

    private School $school;

    private User $guardian;

    private SchoolStudent $student;

    private Driver $driver;

    private SchoolServiceRoute $route;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->school = School::factory()->create(['city_id' => $this->city->id]);

        $owner = User::factory()->create(['city_id' => $this->city->id]);
        $this->company = SchoolCompany::factory()->approved()->create([
            'city_id' => $this->city->id,
            'owner_user_id' => $owner->id,
        ]);
        $this->company->staff()->create(['user_id' => $owner->id, 'role' => 'manager', 'is_active' => true]);

        $vehicle = SchoolVehicle::factory()->create([
            'school_company_id' => $this->company->id,
            'city_id' => $this->city->id,
        ]);

        $this->driver = Driver::factory()->create(['city_id' => $this->city->id]);

        $this->route = SchoolServiceRoute::factory()->create([
            'school_company_id' => $this->company->id,
            'school_id' => $this->school->id,
            'city_id' => $this->city->id,
            'school_vehicle_id' => $vehicle->id,
            'driver_id' => $this->driver->id,
        ]);

        $this->guardian = $this->makePassenger($this->city, 5_000_000);
        $this->student = SchoolStudent::factory()->create([
            'guardian_user_id' => $this->guardian->id,
            'city_id' => $this->city->id,
            'school_id' => $this->school->id,
        ]);
    }

    private function actingAsSchoolDriver(): static
    {
        Sanctum::actingAs($this->driver->user, ['school_driver', 'passenger']);

        return $this;
    }

    private function actingAsCompanyManager(): static
    {
        $this->actingAsAdmin($this->company->owner, Role::SCHOOL_COMPANY_MANAGER);

        return $this;
    }

    private function liveContract(): SchoolServiceContract
    {
        $contracts = app(SchoolContractService::class);

        $contract = $contracts->accept(
            $contracts->request($this->student, $this->company, $this->guardian, [
                'starts_on' => today()->subDay()->toDateString(),
            ]),
            $this->company->owner,
            1_000_000,
        );

        return $contracts->assignRoute($contract, $this->route, $this->company->owner);
    }

    // ── the parent ──────────────────────────────────────────────────────────

    public function test_a_parent_only_sees_approved_companies(): void
    {
        SchoolCompany::factory()->create(['city_id' => $this->city->id, 'name' => 'شرکت تأییدنشده']);

        $names = collect(
            $this->actingAsPassenger($this->guardian)
                ->getJson('/api/v1/school/companies')
                ->assertOk()
                ->json('data')
        )->pluck('name');

        $this->assertCount(1, $names);
        $this->assertSame($this->company->name, $names->first());
    }

    public function test_a_parent_registers_a_child_and_requests_a_contract(): void
    {
        $studentUuid = $this->actingAsPassenger($this->guardian)
            ->postJson('/api/v1/school/students', [
                'first_name' => 'سارا',
                'last_name' => 'محمدی',
                'school_uuid' => $this->school->uuid,
                'grade' => '۳',
                'pickup_address' => 'خیابان ولیعصر',
                'medical_notes' => 'آسم دارد',
            ])->assertCreated()->json('data.uuid');

        $this->actingAsPassenger($this->guardian)
            ->postJson('/api/v1/school/contracts', [
                'student_uuid' => $studentUuid,
                'company_uuid' => $this->company->uuid,
                'direction' => 'both',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested');
    }

    public function test_another_familys_child_is_out_of_reach(): void
    {
        $stranger = SchoolStudent::factory()->create(['city_id' => $this->city->id]);

        $this->actingAsPassenger($this->guardian)
            ->getJson("/api/v1/school/students/{$stranger->uuid}/live")
            ->assertNotFound();

        $this->actingAsPassenger($this->guardian)
            ->patchJson("/api/v1/school/students/{$stranger->uuid}", ['grade' => '۵'])
            ->assertNotFound();
    }

    public function test_no_run_in_progress_is_an_answer_not_an_error(): void
    {
        $this->liveContract();

        // Most of the day there is no van to watch, and the app shows that as
        // a state rather than as a failure.
        $this->actingAsPassenger($this->guardian)
            ->getJson("/api/v1/school/students/{$this->student->uuid}/live")
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.reason', 'no_run_in_progress');
    }

    public function test_a_parent_watches_the_van_once_the_run_starts(): void
    {
        $this->liveContract();
        $run = collect(app(SchoolTripService::class)->scheduleFor($this->route->fresh(), today()))->first();

        $this->actingAsSchoolDriver()
            ->postJson("/api/v1/school/driver/trips/{$run->uuid}/start", ['lat' => 27.19, 'lng' => 56.2666])
            ->assertOk();

        $this->actingAsPassenger($this->guardian)
            ->getJson("/api/v1/school/students/{$this->student->uuid}/live")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonStructure(['data' => ['position' => ['lat', 'lng'], 'eta', 'stops_ahead', 'driver_phone']]);
    }

    // ── the driver ──────────────────────────────────────────────────────────

    public function test_the_driver_gets_the_manifest_in_pickup_order(): void
    {
        $this->liveContract();
        app(SchoolTripService::class)->scheduleFor($this->route->fresh(), today());

        $data = $this->actingAsSchoolDriver()
            ->getJson('/api/v1/school/driver/state')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data['trips']);
        $this->assertSame('to_school', $data['trips'][0]['direction']);
    }

    public function test_the_manifest_carries_what_a_driver_needs_at_the_door(): void
    {
        $this->student->forceFill([
            'medical_notes' => 'آلرژی به بادام‌زمینی',
            'emergency_contact_phone' => '09121110000',
        ])->save();

        $this->liveContract();
        $run = collect(app(SchoolTripService::class)->scheduleFor($this->route->fresh(), today()))->first();

        $row = $this->actingAsSchoolDriver()
            ->getJson("/api/v1/school/driver/trips/{$run->uuid}")
            ->assertOk()
            ->json('data.students.0');

        // The moment these matter is the moment a driver has thirty seconds
        // and one hand free.
        $this->assertSame('آلرژی به بادام‌زمینی', $row['student']['medical_notes']);
        $this->assertSame('09121110000', $row['student']['emergency_contact_phone']);
        $this->assertNotEmpty($row['student']['guardian_phone']);
    }

    public function test_checking_a_child_on_moves_the_counters(): void
    {
        $this->liveContract();
        $run = collect(app(SchoolTripService::class)->scheduleFor($this->route->fresh(), today()))->first();

        $this->actingAsSchoolDriver()->postJson("/api/v1/school/driver/trips/{$run->uuid}/start")->assertOk();

        $rowUuid = $run->students()->firstOrFail()->uuid;

        $this->actingAsSchoolDriver()
            ->postJson("/api/v1/school/driver/students/$rowUuid/pickup", ['lat' => 27.2, 'lng' => 56.2666])
            ->assertOk()
            ->assertJsonPath('data.status', 'picked_up');

        $this->assertSame(1, $run->fresh()->picked_up_count);
    }

    public function test_another_drivers_manifest_is_out_of_reach(): void
    {
        $this->liveContract();
        $run = collect(app(SchoolTripService::class)->scheduleFor($this->route->fresh(), today()))->first();

        $stranger = Driver::factory()->create(['city_id' => $this->city->id]);
        Sanctum::actingAs($stranger->user, ['school_driver']);

        $this->getJson("/api/v1/school/driver/trips/{$run->uuid}")->assertNotFound();
    }

    public function test_a_passenger_token_cannot_reach_the_driver_endpoints(): void
    {
        $this->actingAsPassenger($this->guardian);

        $this->getJson('/api/v1/school/driver/state')->assertForbidden();
    }

    // ── the panel ───────────────────────────────────────────────────────────

    public function test_a_company_manager_sees_only_their_own_contracts(): void
    {
        $this->liveContract();

        $other = SchoolCompany::factory()->approved()->create(['city_id' => $this->city->id]);
        $otherGuardian = $this->makePassenger($this->city);
        $otherStudent = SchoolStudent::factory()->create([
            'guardian_user_id' => $otherGuardian->id,
            'city_id' => $this->city->id,
            'school_id' => $this->school->id,
        ]);
        app(SchoolContractService::class)->request($otherStudent, $other, $otherGuardian, []);

        $rows = $this->actingAsCompanyManager()
            ->getJson('/api/v1/admin/school/contracts')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->student->uuid, $rows[0]['student']['uuid']);
    }

    public function test_a_city_administrator_sees_every_company(): void
    {
        $this->liveContract();
        SchoolCompany::factory()->create(['city_id' => $this->city->id]);

        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]));

        $this->getJson('/api/v1/admin/school/companies')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_only_a_city_administrator_may_approve_a_company(): void
    {
        $pending = SchoolCompany::factory()->create(['city_id' => $this->city->id]);

        $this->actingAsCompanyManager()
            ->postJson("/api/v1/admin/school/companies/{$pending->uuid}/approve")
            ->assertForbidden();

        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]))
            ->postJson("/api/v1/admin/school/companies/{$pending->uuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.is_approved', true);
    }

    public function test_a_company_manager_cannot_touch_another_companys_route(): void
    {
        $other = SchoolCompany::factory()->approved()->create(['city_id' => $this->city->id]);
        $theirRoute = SchoolServiceRoute::factory()->create([
            'school_company_id' => $other->id,
            'school_id' => $this->school->id,
            'city_id' => $this->city->id,
        ]);

        $this->actingAsCompanyManager()
            ->patchJson("/api/v1/admin/school/routes/{$theirRoute->uuid}", ['capacity' => 40])
            ->assertForbidden();
    }

    public function test_accepting_and_placing_a_contract_from_the_panel(): void
    {
        $contracts = app(SchoolContractService::class);
        $contract = $contracts->request($this->student, $this->company, $this->guardian, []);

        $this->actingAsCompanyManager()
            ->postJson("/api/v1/admin/school/contracts/{$contract->uuid}/accept", [
                'fee_amount' => 2_500_000,
                'payment_cycle' => 'monthly',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAsCompanyManager()
            ->postJson("/api/v1/admin/school/contracts/{$contract->uuid}/route", [
                'route_uuid' => $this->route->uuid,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }
}
