<?php

namespace Tests\Feature\Notifications;

use App\Domain\Analytics\Services\DashboardService;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\SchoolTransport\Enums\SchoolCompanyStatus;
use App\Domain\SchoolTransport\Models\School;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Domain\SchoolTransport\Models\SchoolServiceRoute;
use App\Domain\SchoolTransport\Models\SchoolStudent;
use App\Domain\SchoolTransport\Services\SchoolCompanyService;
use App\Domain\SchoolTransport\Services\SchoolContractService;
use App\Domain\SchoolTransport\Services\SchoolInvoiceService;
use App\Domain\Taxi\Enums\TaxiRideStatus;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiRide;
use App\Domain\Taxi\Models\TaxiSettlement;
use App\Domain\Taxi\Models\TaxiShift;
use App\Domain\Taxi\Services\TaxiSettlementService;
use App\Domain\Wallet\Services\WalletService;
use App\Notifications\SchoolCompanyDecisionNotification;
use App\Notifications\SchoolContractNotification;
use App\Notifications\TaxiSettlementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Decisions must reach the person waiting on them.
 *
 * A parent who asked for a school service, a driver who asked to be paid, an
 * owner who registered a company — each is waiting on somebody else's yes or
 * no, and until these notifications existed the only way to learn the answer
 * was to keep opening the app. These tests hold two things: every decision
 * notifies, and no idempotent replay of a decision notifies twice.
 */
class DecisionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->city = $this->makeCity();
    }

    // ── school contracts ────────────────────────────────────────────────

    private function makeContract(): SchoolServiceContract
    {
        $school = School::factory()->create(['city_id' => $this->city->id]);
        $owner = User::factory()->create(['city_id' => $this->city->id]);
        $company = SchoolCompany::factory()->approved()->create([
            'city_id' => $this->city->id,
            'owner_user_id' => $owner->id,
        ]);
        $guardian = $this->makePassenger($this->city, 5_000_000);
        $student = SchoolStudent::factory()->create([
            'guardian_user_id' => $guardian->id,
            'city_id' => $this->city->id,
            'school_id' => $school->id,
        ]);

        return app(SchoolContractService::class)
            ->request($student, $company, $guardian, []);
    }

    public function test_the_guardian_hears_that_the_company_said_yes(): void
    {
        $contract = $this->makeContract();
        $actor = User::factory()->create(['city_id' => $this->city->id]);

        app(SchoolContractService::class)->accept($contract, $actor, 2_000_000);

        Notification::assertSentTo(
            $contract->guardian,
            SchoolContractNotification::class,
            fn (SchoolContractNotification $n) => $n->event === 'accepted'
                && $n->contractUuid === $contract->uuid,
        );
    }

    public function test_the_guardian_hears_the_no_and_its_reason(): void
    {
        $contract = $this->makeContract();
        $actor = User::factory()->create(['city_id' => $this->city->id]);

        app(SchoolContractService::class)->reject($contract, $actor, 'مسیر پوشش داده نمی‌شود');

        Notification::assertSentTo(
            $contract->guardian,
            SchoolContractNotification::class,
            fn (SchoolContractNotification $n) => $n->event === 'rejected'
                && $n->replacements['reason'] === 'مسیر پوشش داده نمی‌شود',
        );
    }

    public function test_the_guardian_hears_which_van_their_child_is_on(): void
    {
        $contract = $this->makeContract();
        $actor = User::factory()->create(['city_id' => $this->city->id]);
        $service = app(SchoolContractService::class);
        $service->accept($contract->fresh(), $actor, 2_000_000);

        $route = SchoolServiceRoute::factory()->create([
            'school_company_id' => $contract->school_company_id,
            'school_id' => $contract->school_id,
            'city_id' => $this->city->id,
            'capacity' => 4,
        ]);

        $service->assignRoute($contract->fresh(), $route, $actor);

        Notification::assertSentTo(
            $contract->guardian,
            SchoolContractNotification::class,
            fn (SchoolContractNotification $n) => $n->event === 'route_assigned'
                && $n->replacements['route'] === $route->name,
        );
    }

    public function test_a_new_invoice_notifies_but_its_idempotent_replay_does_not(): void
    {
        $contract = $this->makeContract();
        $actor = User::factory()->create(['city_id' => $this->city->id]);
        $service = app(SchoolContractService::class);
        $service->accept($contract->fresh(), $actor, 2_000_000);

        $route = SchoolServiceRoute::factory()->create([
            'school_company_id' => $contract->school_company_id,
            'school_id' => $contract->school_id,
            'city_id' => $this->city->id,
            'capacity' => 4,
        ]);
        $service->assignRoute($contract->fresh(), $route, $actor);

        $invoices = app(SchoolInvoiceService::class);
        $invoices->issueFor($contract->fresh());
        // The scheduler running twice returns the same invoice — and must not
        // tell the family twice that they owe one month.
        $invoices->issueFor($contract->fresh());

        Notification::assertSentToTimes(
            $contract->guardian,
            SchoolContractNotification::class,
            // accepted + route_assigned + exactly one invoice_issued.
            3,
        );
        Notification::assertSentTo(
            $contract->guardian,
            SchoolContractNotification::class,
            fn (SchoolContractNotification $n) => $n->event === 'invoice_issued',
        );
    }

    public function test_the_nightly_billing_run_can_build_the_message_it_sends(): void
    {
        // Two, not one, and through the real command: Eloquent only arms the
        // lazy-loading guard on models hydrated from a result set of more than
        // one row, so a single seated child cannot reproduce the crash the
        // 02:00 billing run hit — it chunks every billable contract at once.
        $first = $this->seatedContract();
        $second = $this->seatedContract();

        $this->artisan('school:contracts:bill')->assertSuccessful();

        foreach ([$first, $second] as $contract) {
            Notification::assertSentTo(
                $contract->guardian,
                SchoolContractNotification::class,
                fn (SchoolContractNotification $n) => $n->event === 'invoice_issued'
                    && $n->contractUuid === $contract->uuid,
            );
        }
    }

    /** A contract carried all the way to a seat, which is what makes it billable. */
    private function seatedContract(): SchoolServiceContract
    {
        $contract = $this->makeContract();
        $actor = User::factory()->create(['city_id' => $this->city->id]);
        $service = app(SchoolContractService::class);
        $service->accept($contract->fresh(), $actor, 2_000_000);

        $route = SchoolServiceRoute::factory()->create([
            'school_company_id' => $contract->school_company_id,
            'school_id' => $contract->school_id,
            'city_id' => $this->city->id,
            'capacity' => 4,
        ]);
        $service->assignRoute($contract->fresh(), $route, $actor);

        return $contract->fresh();
    }

    // ── taxi settlements ────────────────────────────────────────────────

    /** @return array{0: Driver, 1: TaxiSettlement} */
    private function makeRequestedSettlement(): array
    {
        $driver = Driver::factory()->create(['city_id' => $this->city->id]);
        $taxi = Taxi::factory()->inService()->create(['city_id' => $this->city->id]);
        $shift = TaxiShift::create([
            'taxi_id' => $taxi->id,
            'driver_id' => $driver->id,
            'city_id' => $this->city->id,
            'service_type' => TaxiServiceType::Charter,
            'started_at' => now(),
            'status' => 'open',
        ]);

        TaxiRide::create([
            'user_id' => $this->makePassenger($this->city)->id,
            'taxi_id' => $taxi->id,
            'driver_id' => $driver->id,
            'taxi_shift_id' => $shift->id,
            'city_id' => $this->city->id,
            'service_type' => TaxiServiceType::Charter,
            'status' => TaxiRideStatus::Completed,
            'fare_amount' => 1_000_000,
            'commission_amount' => 100_000,
            'started_at' => now(),
            'ended_at' => now(),
        ]);

        $settlement = app(TaxiSettlementService::class)->request($driver, $driver->user);

        return [$driver, $settlement];
    }

    public function test_the_driver_hears_the_approval_and_then_the_payment(): void
    {
        [$driver, $settlement] = $this->makeRequestedSettlement();

        app(WalletService::class)->adjust(
            wallet: $this->walletOf($driver->user),
            amount: 900_000,
            reason: 'fares taken',
            initiatedBy: $driver->user,
            idempotencyKey: 'test:credit:settlement',
        );

        $service = app(TaxiSettlementService::class);
        $approver = User::factory()->create(['city_id' => $this->city->id]);
        $service->approve($settlement, $approver);

        Notification::assertSentTo(
            $driver->user,
            TaxiSettlementNotification::class,
            fn (TaxiSettlementNotification $n) => $n->event === 'approved'
                && $n->reference === $settlement->reference,
        );

        $service->markPaid($settlement->fresh(), 'BANK-REF-123');

        Notification::assertSentTo(
            $driver->user,
            TaxiSettlementNotification::class,
            fn (TaxiSettlementNotification $n) => $n->event === 'paid'
                && $n->detail === 'BANK-REF-123',
        );
    }

    public function test_the_driver_hears_the_rejection_and_its_reason(): void
    {
        [$driver, $settlement] = $this->makeRequestedSettlement();

        $actor = User::factory()->create(['city_id' => $this->city->id]);
        app(TaxiSettlementService::class)->reject($settlement, $actor, 'مدارک ناقص است');

        Notification::assertSentTo(
            $driver->user,
            TaxiSettlementNotification::class,
            fn (TaxiSettlementNotification $n) => $n->event === 'rejected'
                && $n->detail === 'مدارک ناقص است',
        );
    }

    // ── school companies ────────────────────────────────────────────────

    public function test_the_owner_hears_each_decision_but_a_repeated_approval_stays_silent(): void
    {
        $owner = User::factory()->create(['city_id' => $this->city->id]);
        $company = SchoolCompany::factory()->create([
            'city_id' => $this->city->id,
            'owner_user_id' => $owner->id,
            'status' => SchoolCompanyStatus::PendingApproval,
        ]);
        $admin = User::factory()->create(['city_id' => $this->city->id]);
        $service = app(SchoolCompanyService::class);

        $service->approve($company, $admin);
        // Approving an already-active company is the early-return path, and a
        // second "you are approved" would read as a glitch, not good news.
        $service->approve($company->fresh(), $admin);

        Notification::assertSentToTimes($owner, SchoolCompanyDecisionNotification::class, 1);

        $service->suspend($company->fresh(), $admin, 'شکایت ایمنی در دست بررسی');

        Notification::assertSentTo(
            $owner,
            SchoolCompanyDecisionNotification::class,
            fn (SchoolCompanyDecisionNotification $n) => $n->event === 'suspended'
                && $n->reason === 'شکایت ایمنی در دست بررسی',
        );
    }

    public function test_a_rejected_company_owner_gets_the_reason(): void
    {
        $owner = User::factory()->create(['city_id' => $this->city->id]);
        $company = SchoolCompany::factory()->create([
            'city_id' => $this->city->id,
            'owner_user_id' => $owner->id,
            'status' => SchoolCompanyStatus::PendingApproval,
        ]);
        $admin = User::factory()->create(['city_id' => $this->city->id]);

        app(SchoolCompanyService::class)->reject($company, $admin, 'مجوز حمل‌ونقل ارائه نشده');

        Notification::assertSentTo(
            $owner,
            SchoolCompanyDecisionNotification::class,
            fn (SchoolCompanyDecisionNotification $n) => $n->event === 'rejected'
                && $n->reason === 'مجوز حمل‌ونقل ارائه نشده',
        );
    }

    // ── dashboard ───────────────────────────────────────────────────────

    public function test_the_dashboard_counts_taxis_and_school_runs_too(): void
    {
        $kpis = app(DashboardService::class)->kpis($this->city);

        foreach ([
            'taxis_on_shift',
            'taxi_rides_today',
            'taxi_revenue_today',
            'school_runs_today',
            'school_runs_live',
            'school_children_aboard',
            'school_contracts_active',
        ] as $key) {
            $this->assertArrayHasKey($key, $kpis);
            $this->assertSame(0, $kpis[$key], "KPI {$key} should count an empty city as zero");
        }

        Driver::factory()->create(['city_id' => $this->city->id]);
        $taxi = Taxi::factory()->inService()->create(['city_id' => $this->city->id]);
        TaxiShift::create([
            'taxi_id' => $taxi->id,
            'driver_id' => Driver::factory()->create(['city_id' => $this->city->id])->id,
            'city_id' => $this->city->id,
            'service_type' => TaxiServiceType::Line,
            'started_at' => now(),
            'status' => 'open',
        ]);

        cache()->forget("dashboard:kpi:{$this->city->id}");

        $this->assertSame(1, app(DashboardService::class)->kpis($this->city)['taxis_on_shift']);
    }
}
