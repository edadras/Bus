<?php

namespace Tests\Feature\Taxi;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Enums\SettlementStatus;
use App\Domain\Network\Models\City;
use App\Domain\Taxi\Enums\TaxiRideStatus;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiRide;
use App\Domain\Taxi\Models\TaxiShift;
use App\Domain\Taxi\Services\TaxiSettlementService;
use App\Domain\Wallet\Services\WalletService;
use App\Support\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paying a taxi driver out.
 *
 * Fares land in the driver's wallet as they are taken, so a payout is a
 * transfer of money that is already theirs. What the settlement adds is the
 * claim, and the claim is the whole point: a ride belongs to exactly one
 * payout, which is what makes the run safe to repeat after a failure.
 */
class TaxiSettlementTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Driver $driver;

    private Taxi $taxi;

    private TaxiShift $shift;

    private TaxiSettlementService $settlements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settlements = app(TaxiSettlementService::class);
        $this->city = $this->makeCity();
        $this->driver = Driver::factory()->create(['city_id' => $this->city->id]);
        $this->taxi = Taxi::factory()->inService()->create(['city_id' => $this->city->id]);

        $this->shift = TaxiShift::create([
            'taxi_id' => $this->taxi->id,
            'driver_id' => $this->driver->id,
            'city_id' => $this->city->id,
            'service_type' => TaxiServiceType::Charter,
            'started_at' => now(),
            'status' => 'open',
        ]);
    }

    private function completedRide(int $fare, int $commission = 0): TaxiRide
    {
        return TaxiRide::create([
            'user_id' => $this->makePassenger($this->city)->id,
            'taxi_id' => $this->taxi->id,
            'driver_id' => $this->driver->id,
            'taxi_shift_id' => $this->shift->id,
            'city_id' => $this->city->id,
            'service_type' => TaxiServiceType::Charter,
            'status' => TaxiRideStatus::Completed,
            'fare_amount' => $fare,
            'commission_amount' => $commission,
            'started_at' => now(),
            'ended_at' => now(),
        ]);
    }

    /** Credit the driver's wallet the way a real fare would have. */
    private function creditDriver(int $amount): void
    {
        app(WalletService::class)->adjust(
            wallet: $this->walletOf($this->driver->user),
            amount: $amount,
            reason: 'fares taken',
            initiatedBy: $this->driver->user,
            idempotencyKey: 'test:credit:'.$amount.':'.uniqid(),
        );
    }

    public function test_a_request_claims_the_rides_it_is_paying_for(): void
    {
        $first = $this->completedRide(1_000_000, 100_000);
        $second = $this->completedRide(500_000, 50_000);

        $settlement = $this->settlements->request($this->driver, $this->driver->user);

        $this->assertSame(2, $settlement->ride_count);
        $this->assertSame(1_500_000, $settlement->gross_amount);
        $this->assertSame(1_350_000, $settlement->net_amount);
        $this->assertSame($settlement->id, $first->fresh()->taxi_settlement_id);
        $this->assertSame($settlement->id, $second->fresh()->taxi_settlement_id);
    }

    public function test_a_ride_is_never_claimed_by_two_payouts(): void
    {
        $this->completedRide(1_000_000, 100_000);
        $this->settlements->request($this->driver, $this->driver->user);

        // The second request finds nothing left, which is exactly what makes
        // the payout job safe to re-run after a failure.
        $this->assertRefused(
            'nothing_to_settle',
            fn () => $this->settlements->request($this->driver, $this->driver->user),
        );
    }

    public function test_an_unpaid_ride_is_not_paid_out(): void
    {
        $this->completedRide(1_000_000, 100_000);

        // An unpaid ride carries no fare, only a debt: `fare_amount` means
        // money that moved, and nothing moved here.
        $unpaid = $this->completedRide(0, 0);
        $unpaid->forceFill(['status' => TaxiRideStatus::Unpaid, 'outstanding_amount' => 400_000])->save();

        $settlement = $this->settlements->request($this->driver, $this->driver->user);

        // The passenger never paid it, so there is nothing to hand over.
        $this->assertSame(1, $settlement->ride_count);
        $this->assertNull($unpaid->fresh()->taxi_settlement_id);
    }

    public function test_approval_debits_the_drivers_wallet(): void
    {
        $this->completedRide(2_000_000, 200_000);
        $this->creditDriver(1_800_000);

        $settlement = $this->settlements->request($this->driver, $this->driver->user);
        $approved = $this->settlements->approve($settlement, User::factory()->create());

        $this->assertSame(SettlementStatus::Approved, $approved->status);
        // The money leaves the platform's float at approval, not when the bank
        // transfer clears — otherwise the driver could spend it twice.
        $this->assertSame(0, $this->walletOf($this->driver->user)->balance);
    }

    public function test_a_payout_larger_than_the_wallet_is_refused(): void
    {
        $this->completedRide(2_000_000, 200_000);

        $settlement = $this->settlements->request($this->driver, $this->driver->user);

        $this->assertRefused(
            'settlement_exceeds_balance',
            fn () => $this->settlements->approve($settlement, User::factory()->create()),
        );
    }

    public function test_rejecting_releases_the_rides_for_the_next_payout(): void
    {
        $ride = $this->completedRide(2_000_000, 200_000);

        $settlement = $this->settlements->request($this->driver, $this->driver->user);
        $this->settlements->reject($settlement, User::factory()->create(), 'documents missing');

        $this->assertNull($ride->fresh()->taxi_settlement_id);

        $this->creditDriver(1_800_000);
        $retry = $this->settlements->request($this->driver, $this->driver->user);

        $this->assertSame(1, $retry->ride_count);
    }

    public function test_a_payout_below_the_minimum_is_refused_at_request_time(): void
    {
        $this->completedRide(1_000, 0);

        // Told now, not after a day of waiting.
        $this->assertRefused(
            'settlement_below_minimum',
            fn () => $this->settlements->request($this->driver, $this->driver->user),
        );
    }

    public function test_the_pending_balance_says_what_could_be_claimed(): void
    {
        $this->completedRide(1_000_000, 100_000);
        $this->completedRide(500_000, 50_000);

        $pending = $this->settlements->pendingBalance($this->driver);

        $this->assertSame(2, $pending['ride_count']);
        $this->assertSame(1_350_000, $pending['net_amount']);
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
