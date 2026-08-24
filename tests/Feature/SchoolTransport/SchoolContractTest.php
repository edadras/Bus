<?php

namespace Tests\Feature\SchoolTransport;

use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\SchoolTransport\Enums\SchoolContractStatus;
use App\Domain\SchoolTransport\Models\School;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Domain\SchoolTransport\Models\SchoolServiceRoute;
use App\Domain\SchoolTransport\Models\SchoolStudent;
use App\Domain\SchoolTransport\Services\SchoolContractService;
use App\Domain\SchoolTransport\Services\SchoolInvoiceService;
use App\Domain\Wallet\Services\LedgerService;
use App\Support\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The agreement between a family and a company.
 *
 * Four states, separate on purpose: a parent requests, a company accepts, the
 * company places the child on a route, and only then is the contract active.
 * These tests hold that sequence, and the one rule that matters more than the
 * sequence — an unapproved company is invisible and uncontractable.
 */
class SchoolContractTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private SchoolCompany $company;

    private School $school;

    private User $guardian;

    private SchoolStudent $student;

    private SchoolContractService $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = app(SchoolContractService::class);
        $this->city = $this->makeCity();
        $this->school = School::factory()->create(['city_id' => $this->city->id]);

        $owner = User::factory()->create(['city_id' => $this->city->id]);
        $this->company = SchoolCompany::factory()->approved()->create([
            'city_id' => $this->city->id,
            'owner_user_id' => $owner->id,
            'commission_bps' => 500,
        ]);

        $this->guardian = $this->makePassenger($this->city, 5_000_000);
        $this->student = SchoolStudent::factory()->create([
            'guardian_user_id' => $this->guardian->id,
            'city_id' => $this->city->id,
            'school_id' => $this->school->id,
        ]);
    }

    private function route(array $overrides = []): SchoolServiceRoute
    {
        return SchoolServiceRoute::factory()->create(array_merge([
            'school_company_id' => $this->company->id,
            'school_id' => $this->school->id,
            'city_id' => $this->city->id,
            'capacity' => 2,
        ], $overrides));
    }

    private function request(): SchoolServiceContract
    {
        return $this->contracts->request($this->student, $this->company, $this->guardian, [
            'starts_on' => today()->toDateString(),
        ]);
    }

    public function test_a_parent_cannot_contract_with_an_unapproved_company(): void
    {
        $unvetted = SchoolCompany::factory()->create(['city_id' => $this->city->id]);

        // A list of unvetted strangers offering to drive children is not a
        // marketplace, it is a hazard.
        $this->assertRefused('company_not_approved', fn () => $this->contracts->request(
            $this->student,
            $unvetted,
            $this->guardian,
            [],
        ));
    }

    public function test_a_parent_cannot_contract_for_another_familys_child(): void
    {
        $stranger = SchoolStudent::factory()->create(['city_id' => $this->city->id]);

        $this->assertRefused('not_your_student', fn () => $this->contracts->request(
            $stranger,
            $this->company,
            $this->guardian,
            [],
        ));
    }

    public function test_accepting_names_the_fee_but_does_not_give_a_seat(): void
    {
        $contract = $this->request();

        $accepted = $this->contracts->accept($contract, $this->company->owner, 3_000_000);

        // A company takes a family in August and works out the vans in
        // September; "accepted" must not imply a seat that does not exist yet.
        $this->assertSame(SchoolContractStatus::Approved, $accepted->status);
        $this->assertNull($accepted->school_service_route_id);
        $this->assertSame(3_000_000, $accepted->fee_amount);
    }

    public function test_placing_the_child_on_a_route_is_what_makes_it_active(): void
    {
        $contract = $this->contracts->accept($this->request(), $this->company->owner, 3_000_000);
        $route = $this->route();

        $active = $this->contracts->assignRoute($contract, $route, $this->company->owner);

        $this->assertSame(SchoolContractStatus::Active, $active->status);
        $this->assertSame($route->id, $active->school_service_route_id);
        $this->assertNotNull($active->activated_at);
    }

    public function test_a_route_cannot_carry_more_children_than_it_has_belts(): void
    {
        $route = $this->route(['capacity' => 1]);

        $first = $this->contracts->accept($this->request(), $this->company->owner, 1_000_000);
        $this->contracts->assignRoute($first, $route, $this->company->owner);

        $sibling = SchoolStudent::factory()->create([
            'guardian_user_id' => $this->guardian->id,
            'city_id' => $this->city->id,
            'school_id' => $this->school->id,
        ]);

        $second = $this->contracts->accept(
            $this->contracts->request($sibling, $this->company, $this->guardian, []),
            $this->company->owner,
            1_000_000,
        );

        $this->assertRefused(
            'route_is_full',
            fn () => $this->contracts->assignRoute($second, $route, $this->company->owner),
        );
    }

    public function test_a_route_from_another_company_cannot_be_used(): void
    {
        $contract = $this->contracts->accept($this->request(), $this->company->owner, 1_000_000);

        $other = SchoolCompany::factory()->approved()->create(['city_id' => $this->city->id]);
        $theirRoute = $this->route(['school_company_id' => $other->id]);

        $this->assertRefused(
            'route_belongs_to_another_company',
            fn () => $this->contracts->assignRoute($contract, $theirRoute, $this->company->owner),
        );
    }

    public function test_one_child_cannot_be_on_two_vans(): void
    {
        $this->request();

        $other = SchoolCompany::factory()->approved()->create(['city_id' => $this->city->id]);

        // Left as a refusal rather than a silent replacement: ending the old
        // arrangement is the parent's decision, and it may already be paid for.
        $this->assertRefused('student_already_contracted', fn () => $this->contracts->request(
            $this->student,
            $other,
            $this->guardian,
            [],
        ));
    }

    public function test_siblings_hold_separate_contracts(): void
    {
        $route = $this->route(['capacity' => 4]);

        $first = $this->contracts->accept($this->request(), $this->company->owner, 1_000_000);
        $this->contracts->assignRoute($first, $route, $this->company->owner);

        $sibling = SchoolStudent::factory()->create([
            'guardian_user_id' => $this->guardian->id,
            'city_id' => $this->city->id,
            'school_id' => $this->school->id,
        ]);

        $second = $this->contracts->accept(
            $this->contracts->request($sibling, $this->company, $this->guardian, []),
            $this->company->owner,
            1_000_000,
        );
        $this->contracts->assignRoute($second, $route, $this->company->owner);

        // One leaving mid-year must not cancel the other's place.
        $this->contracts->end($first, $this->guardian);

        $this->assertSame(SchoolContractStatus::Ended, $first->fresh()->status);
        $this->assertSame(SchoolContractStatus::Active, $second->fresh()->status);
    }

    // ── money ───────────────────────────────────────────────────────────────

    public function test_an_invoice_is_issued_once_per_period(): void
    {
        $contract = $this->activeContract(2_000_000);
        $invoices = app(SchoolInvoiceService::class);

        $first = $invoices->issueFor($contract);
        $second = $invoices->issueFor($contract);

        // A scheduler that runs twice must not bill a family twice for a month.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $contract->invoices()->count());
    }

    public function test_paying_moves_the_fee_to_the_company_net_of_commission(): void
    {
        $contract = $this->activeContract(2_000_000);
        $invoices = app(SchoolInvoiceService::class);

        $invoice = $invoices->issueFor($contract);
        $paid = $invoices->pay($invoice, $this->guardian);

        $this->assertSame('paid', $paid->status->value);
        $this->assertSame(3_000_000, $this->walletOf($this->guardian)->balance);
        // 5% withheld, the rest is the company's.
        $this->assertSame(1_900_000, $this->walletOf($this->company->owner)->balance);
        $this->assertTrue(app(LedgerService::class)->verify($this->walletOf($this->guardian))['ok']);
    }

    public function test_another_familys_invoice_cannot_be_paid(): void
    {
        $contract = $this->activeContract(1_000_000);
        $invoice = app(SchoolInvoiceService::class)->issueFor($contract);

        $stranger = $this->makePassenger($this->city, 5_000_000);

        $this->assertRefused(
            'not_your_invoice',
            fn () => app(SchoolInvoiceService::class)->pay($invoice, $stranger),
        );
    }

    public function test_leaving_does_not_cancel_what_is_owed(): void
    {
        $contract = $this->activeContract(1_000_000);
        $invoice = app(SchoolInvoiceService::class)->issueFor($contract);

        $this->contracts->end($contract, $this->guardian, 'moving house');

        // A family that leaves owing two months still owes two months;
        // cancelling the debt with the contract would make leaving the
        // cheapest way not to pay.
        $this->assertTrue($invoice->fresh()->status->isPayable());
    }

    private function activeContract(int $fee): SchoolServiceContract
    {
        $contract = $this->contracts->accept($this->request(), $this->company->owner, $fee);

        return $this->contracts->assignRoute($contract, $this->route(), $this->company->owner);
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

    /**
     * A child registered without a school cannot be contracted for.
     *
     * The API lets a parent add a child before they have picked a school, and
     * every route serves exactly one — so this was a database constraint
     * violation surfacing as a 500 on the one screen a parent uses most.
     */
    public function test_a_child_with_no_school_is_refused_with_a_message_not_a_crash(): void
    {
        $student = SchoolStudent::factory()->create([
            'guardian_user_id' => $this->guardian->id,
            'city_id' => $this->city->id,
            'school_id' => null,
        ]);

        try {
            $this->contracts->request($student, $this->company, $this->guardian, []);
            $this->fail('Expected the request to be refused.');
        } catch (DomainException $e) {
            $this->assertSame('student_has_no_school', $e->errorCode());
        }

        $this->assertSame(0, SchoolServiceContract::where('school_student_id', $student->id)->count());
    }

    /**
     * A fresh request answers with real numbers, not nulls.
     *
     * `create()` hands back a model that knows only what was written, so every
     * column the database defaults reached the parent's first response as a
     * null — and a screen that shows a fee has to be able to tell "nothing
     * agreed yet" from "we do not know".
     */
    public function test_a_new_request_answers_with_its_defaults_filled_in(): void
    {
        $contract = $this->contracts->request($this->student, $this->company, $this->guardian, []);

        $this->assertSame(0, $contract->fee_amount);
        $this->assertSame(0, $contract->discount_bps);
        $this->assertSame(0, $contract->payableAmount());
        $this->assertNotNull($contract->payment_cycle);
    }
}
