<?php

namespace Tests\Feature\SchoolTransport;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\SchoolTransport\Enums\SchoolAttendanceStatus;
use App\Domain\SchoolTransport\Enums\SchoolServiceDirection;
use App\Domain\SchoolTransport\Enums\SchoolTripStatus;
use App\Domain\SchoolTransport\Models\School;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Domain\SchoolTransport\Models\SchoolServiceRoute;
use App\Domain\SchoolTransport\Models\SchoolStudent;
use App\Domain\SchoolTransport\Models\SchoolTrip;
use App\Domain\SchoolTransport\Models\SchoolTripStudent;
use App\Domain\SchoolTransport\Models\SchoolVehicle;
use App\Domain\SchoolTransport\Services\SchoolAttendanceService;
use App\Domain\SchoolTransport\Services\SchoolContractService;
use App\Domain\SchoolTransport\Services\SchoolLiveService;
use App\Domain\SchoolTransport\Services\SchoolTripService;
use App\Notifications\SchoolAttendanceNotification;
use App\Support\Exceptions\DomainException;
use App\Support\Geo\Coordinate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A morning's run.
 *
 * The two properties these tests exist for: a child nobody touched is visibly
 * unaccounted for rather than silently absent, and a van's position is visible
 * to the families it is carrying, while it is carrying them, and to nobody else
 * at any other time.
 */
class SchoolRunTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private SchoolCompany $company;

    private School $school;

    private SchoolServiceRoute $route;

    private Driver $driver;

    private User $guardian;

    private SchoolStudent $student;

    private SchoolTripService $trips;

    private SchoolAttendanceService $attendance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trips = app(SchoolTripService::class);
        $this->attendance = app(SchoolAttendanceService::class);

        $this->city = $this->makeCity();
        $this->school = School::factory()->create([
            'city_id' => $this->city->id,
            'lat' => 27.1832,
            'lng' => 56.2666,
        ]);

        $this->company = SchoolCompany::factory()->approved()->create([
            'city_id' => $this->city->id,
            'owner_user_id' => User::factory()->create(['city_id' => $this->city->id])->id,
        ]);

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
            'capacity' => 10,
        ]);

        $this->guardian = $this->makePassenger($this->city);
        $this->student = SchoolStudent::factory()->create([
            'guardian_user_id' => $this->guardian->id,
            'city_id' => $this->city->id,
            'school_id' => $this->school->id,
            'pickup_lat' => 27.2000,
            'pickup_lng' => 56.2666,
        ]);

        $this->contractFor($this->student);
    }

    private function contractFor(SchoolStudent $student): SchoolServiceContract
    {
        $contracts = app(SchoolContractService::class);

        $contract = $contracts->accept(
            $contracts->request($student, $this->company, $student->guardian, [
                'starts_on' => today()->subDay()->toDateString(),
            ]),
            $this->company->owner,
            1_000_000,
        );

        return $contracts->assignRoute($contract, $this->route, $this->company->owner);
    }

    private function morningRun(): SchoolTrip
    {
        $runs = $this->trips->scheduleFor($this->route->fresh(), today());

        return collect($runs)->firstWhere('direction', SchoolServiceDirection::ToSchool);
    }

    private function rowFor(SchoolTrip $trip, SchoolStudent $student): SchoolTripStudent
    {
        return $trip->students()->where('school_student_id', $student->id)->firstOrFail();
    }

    // ── scheduling ──────────────────────────────────────────────────────────

    public function test_scheduling_creates_one_run_each_way_with_the_children_on_it(): void
    {
        $runs = $this->trips->scheduleFor($this->route, today());

        $this->assertCount(2, $runs);
        $this->assertSame(1, $runs[0]->expected_count);
        $this->assertSame(SchoolAttendanceStatus::Pending, $this->rowFor($runs[0], $this->student)->status);
    }

    public function test_scheduling_twice_does_not_create_a_second_run(): void
    {
        $first = $this->morningRun();
        $second = $this->morningRun();

        // A second tap on Start must not produce a parallel run with half the
        // children on it.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SchoolTrip::where('direction', 'to_school')->count());
    }

    public function test_the_morning_collects_from_the_furthest_door_first(): void
    {
        $near = SchoolStudent::factory()->create([
            'guardian_user_id' => $this->makePassenger($this->city)->id,
            'city_id' => $this->city->id,
            'school_id' => $this->school->id,
            'pickup_lat' => 27.1850,
            'pickup_lng' => 56.2666,
        ]);
        $this->contractFor($near);

        $run = $this->morningRun();

        // Going in, the van starts at the far end and works towards the school,
        // so the child living further out is collected first.
        $this->assertSame(1, $this->rowFor($run, $this->student)->sequence);
        $this->assertSame(2, $this->rowFor($run, $near)->sequence);
    }

    // ── running ─────────────────────────────────────────────────────────────

    public function test_a_van_with_lapsed_insurance_does_not_set_off(): void
    {
        $this->route->vehicle->forceFill(['insurance_expires_at' => today()->subDay()])->save();

        $run = $this->morningRun();

        $this->assertRefused(
            'insurance_expired',
            fn () => $this->trips->start($run, $this->driver),
        );
    }

    public function test_a_driver_whose_licence_expired_does_not_set_off(): void
    {
        $this->driver->forceFill(['license_expires_at' => today()->subDay()])->save();

        $run = $this->morningRun();

        $this->assertRefused(
            'license_expired',
            fn () => $this->trips->start($run, $this->driver->fresh()),
        );
    }

    public function test_another_drivers_run_cannot_be_started(): void
    {
        $stranger = Driver::factory()->create(['city_id' => $this->city->id]);

        $this->assertRefused(
            'not_your_trip',
            fn () => $this->trips->start($this->morningRun(), $stranger),
        );
    }

    public function test_checking_a_child_on_and_off_tells_the_parent_both_times(): void
    {
        Notification::fake();

        $run = $this->trips->start($this->morningRun(), $this->driver);
        $row = $this->rowFor($run, $this->student);

        $this->attendance->pickUp($row, $this->driver->user, new Coordinate(27.2, 56.2666));
        $this->attendance->dropOff($row->fresh(), $this->driver->user);

        $this->assertSame(SchoolAttendanceStatus::DroppedOff, $row->fresh()->status);
        $this->assertNotNull($row->fresh()->picked_up_at);
        // A parent who learns at four o'clock what happened at seven has been
        // failed by the system, not by the driver.
        Notification::assertSentToTimes($this->guardian, SchoolAttendanceNotification::class, 2);
    }

    public function test_a_child_cannot_be_set_down_without_being_picked_up(): void
    {
        $run = $this->trips->start($this->morningRun(), $this->driver);

        $this->assertRefused(
            'child_not_aboard',
            fn () => $this->attendance->dropOff($this->rowFor($run, $this->student), $this->driver->user),
        );
    }

    public function test_attendance_cannot_be_recorded_before_the_run_starts(): void
    {
        $run = $this->morningRun();

        $this->assertRefused(
            'trip_not_live',
            fn () => $this->attendance->pickUp($this->rowFor($run, $this->student), $this->driver->user),
        );
    }

    public function test_who_recorded_it_is_kept(): void
    {
        $run = $this->trips->start($this->morningRun(), $this->driver);
        $row = $this->rowFor($run, $this->student);

        $this->attendance->markAbsent($row, $this->driver->user, 'nobody at the door');

        // "The child was marked absent" is a claim somebody made, and the first
        // question a parent asks is who.
        $this->assertSame($this->driver->user_id, $row->fresh()->recorded_by);
        $this->assertSame('nobody at the door', $row->fresh()->note);
    }

    public function test_a_parent_can_say_in_advance_that_the_child_is_not_travelling(): void
    {
        $run = $this->morningRun();
        $row = $this->rowFor($run, $this->student);

        $this->attendance->reportAbsenceByGuardian($row, $this->guardian, 'doctor appointment');

        $this->assertSame(SchoolAttendanceStatus::Absent, $row->fresh()->status);
        $this->assertSame($this->guardian->id, $row->fresh()->recorded_by);
    }

    public function test_finishing_a_run_settles_everyone_left_pending(): void
    {
        $run = $this->trips->start($this->morningRun(), $this->driver);

        $completed = $this->trips->complete($run);

        // At the end of a journey every child has either travelled or not; a
        // row that says neither is the one a parent will ask about.
        $this->assertSame(SchoolTripStatus::Completed, $completed->status);
        $this->assertSame(SchoolAttendanceStatus::NoShow, $this->rowFor($run, $this->student)->fresh()->status);
        $this->assertSame(1, $completed->absent_count);
    }

    // ── who may watch ───────────────────────────────────────────────────────

    public function test_a_parent_watches_the_van_while_their_child_is_on_it(): void
    {
        $live = app(SchoolLiveService::class);

        $run = $this->trips->start($this->morningRun(), $this->driver, new Coordinate(27.19, 56.2666));
        $row = $this->rowFor($run, $this->student);

        $view = $live->forGuardian($row, $this->guardian);

        $this->assertSame('in_progress', $view['status']);
        $this->assertNotNull($view['position']);
        $this->assertIsInt($view['distance_meters']);
        // A straight line is not a road, and saying so is the difference
        // between a useful estimate and a broken promise.
        $this->assertTrue($view['eta']['is_approximate']);
    }

    public function test_another_familys_van_cannot_be_watched(): void
    {
        $live = app(SchoolLiveService::class);

        $run = $this->trips->start($this->morningRun(), $this->driver);
        $row = $this->rowFor($run, $this->student);

        $this->assertRefused(
            'not_your_student',
            fn () => $live->forGuardian($row, $this->makePassenger($this->city)),
        );
    }

    public function test_the_van_stops_being_watchable_once_the_child_is_home(): void
    {
        $live = app(SchoolLiveService::class);

        $run = $this->trips->start($this->morningRun(), $this->driver);
        $row = $this->rowFor($run, $this->student);

        $this->attendance->pickUp($row, $this->driver->user);
        $this->attendance->dropOff($row->fresh(), $this->driver->user);

        // Otherwise a parent would keep watching the van drive on to other
        // families' houses after their own child is safely there.
        $this->assertRefused(
            'child_journey_finished',
            fn () => $live->forGuardian($row->fresh(), $this->guardian),
        );
    }

    public function test_a_run_that_is_not_under_way_cannot_be_watched(): void
    {
        $live = app(SchoolLiveService::class);

        $run = $this->morningRun();
        $row = $this->rowFor($run, $this->student);

        $this->assertRefused('trip_not_live', fn () => $live->forGuardian($row, $this->guardian));
    }

    private function assertRefused(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected the operation to be refused with [$code].");
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }
}
