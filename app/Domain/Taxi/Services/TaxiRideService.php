<?php

namespace App\Domain\Taxi\Services;

use App\Domain\Fleet\DTO\QrToken;
use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Identity\Models\User;
use App\Domain\Taxi\DTO\TaxiFareQuote;
use App\Domain\Taxi\Enums\TaxiRideStatus;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Events\TaxiFarePaid;
use App\Domain\Taxi\Events\TaxiRideEnded;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiRide;
use App\Domain\Taxi\Models\TaxiShift;
use App\Domain\Wallet\Services\WalletService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\InsufficientFundsException;
use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Riding in a taxi: scan, price, confirm, and — for a meter — end.
 *
 * The three modes differ in when money moves, and only in that:
 *
 *   line     paid on boarding, then the passenger occupies a seat
 *   charter  paid on boarding at the price the driver named
 *   meter    nothing is charged until the ride ends
 *
 * Everything else is common, including the rule that matters most: the client
 * never supplies a price. It may only echo back the price the server quoted,
 * and a mismatch is refused rather than reconciled. A confirmation that carries
 * a different figure from the one the passenger was shown is either a bug or an
 * attack, and neither should result in a charge.
 */
class TaxiRideService
{
    public function __construct(
        private readonly TaxiQrService $qr,
        private readonly QrTokenService $tokens,
        private readonly TaxiTariffEngine $tariffs,
        private readonly TaxiMeterService $meter,
        private readonly WalletService $wallets,
        private readonly TaxiLiveService $live,
    ) {}

    /**
     * Price a scan without committing to it.
     *
     * Deliberately consumes nothing: a passenger who looks at a charter price
     * and decides against it must not have burned a code they might need in
     * five seconds when they change their mind.
     *
     * @return array{quote: TaxiFareQuote, taxi: Taxi, shift: TaxiShift, token: QrToken}
     */
    public function quote(User $user, string $rawToken, array $context = []): array
    {
        $resolved = $this->qr->resolveScan($rawToken);
        /** @var Taxi $taxi */
        $taxi = $resolved['taxi'];
        /** @var QrToken $token */
        $token = $resolved['token'];

        $shift = $this->openShiftFor($taxi);

        $this->assertPassengerMayRide($user, $taxi);
        $this->assertNearTaxi($taxi, $this->positionFrom($context));

        return [
            'quote' => $this->priceFor($user, $shift),
            'taxi' => $taxi,
            'shift' => $shift,
            'token' => $token,
        ];
    }

    /**
     * Commit to the ride.
     *
     * @param  int|null  $acceptedAmount  what the passenger was shown, echoed back
     */
    public function confirm(
        User $user,
        string $rawToken,
        ?int $acceptedAmount = null,
        array $context = [],
    ): TaxiRide {
        $this->assertNotRateLimited($user);

        $resolved = $this->qr->resolveScan($rawToken);
        /** @var Taxi $taxi */
        $taxi = $resolved['taxi'];
        /** @var QrToken $token */
        $token = $resolved['token'];

        $shift = $this->openShiftFor($taxi);

        $this->assertPassengerMayRide($user, $taxi);
        $position = $this->positionFrom($context);
        $this->assertNearTaxi($taxi, $position);

        $quote = $this->priceFor($user, $shift);

        $this->assertAmountMatches($quote, $acceptedAmount);

        // Claimed before the transaction so the token is single-use even under
        // concurrency, and released again if anything below fails so the
        // passenger is not made to wait for a fresh code they did not spend.
        $this->tokens->consumeNonce($token, $this->nonceScopeFor($shift, $user));

        try {
            return DB::transaction(function () use ($user, $taxi, $shift, $quote, $token, $position, $context): TaxiRide {
                $lockedShift = TaxiShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();

                if (! $lockedShift->isOpen()) {
                    throw DomainException::make('taxi_not_in_service', 422);
                }

                $duplicate = TaxiRide::where('user_id', $user->id)->open()->exists();

                if ($duplicate) {
                    throw DomainException::make('ride_already_in_progress', 409);
                }

                $ride = TaxiRide::create([
                    'user_id' => $user->id,
                    'taxi_id' => $taxi->id,
                    'driver_id' => $lockedShift->driver_id,
                    'taxi_shift_id' => $lockedShift->id,
                    'city_id' => $lockedShift->city_id,
                    'taxi_line_id' => $lockedShift->taxi_line_id,
                    'taxi_tariff_id' => $quote->tariff?->id,
                    'service_type' => $lockedShift->service_type,
                    'status' => TaxiRideStatus::Active,
                    'quoted_amount' => $quote->amount,
                    'fare_breakdown' => $quote->breakdown,
                    'start_lat' => $position?->lat ?? $taxi->last_lat,
                    'start_lng' => $position?->lng ?? $taxi->last_lng,
                    'qr_public_id' => $token->publicId,
                    'token_nonce' => $token->nonce,
                    'device_fingerprint' => $context['device'] ?? null,
                    'client_ip' => $context['ip'] ?? null,
                    'started_at' => now(),
                ]);

                // A metered ride is priced when it ends; the other two are paid
                // on the spot, which is what the passenger just agreed to.
                if ($lockedShift->service_type->isPricedUpFront()) {
                    $this->settle($ride, $quote->amount, $user);
                }

                $lockedShift->forceFill([
                    'ride_count' => $lockedShift->ride_count + 1,
                    'boarding_count' => $lockedShift->boarding_count + 1,
                    'onboard_count' => $lockedShift->onboard_count + 1,
                    // The named price belongs to this hire and no other.
                    'pending_charter_amount' => null,
                    'pending_charter_set_at' => null,
                ])->save();

                $this->live->publish($lockedShift->fresh(['taxi', 'line']));

                if ($ride->fare_amount > 0) {
                    TaxiFarePaid::dispatch(
                        $lockedShift->id,
                        $lockedShift->driver_id,
                        $ride->uuid,
                        $ride->fare_amount,
                        $ride->fare_amount - $ride->commission_amount,
                        $lockedShift->onboard_count,
                        $lockedShift->fresh()->gross_minor,
                    );
                }

                return $ride->fresh();
            }, 3);
        } catch (\Throwable $e) {
            $this->tokens->releaseNonce($token, $this->nonceScopeFor($shift, $user));

            throw $e;
        }
    }

    /**
     * End a ride.
     *
     * For a meter this is where the fare is finally known, and where it can
     * fail: a wallet that could cover the minimum at the kerb may not cover
     * forty minutes of traffic. That outcome is recorded as a debt on a
     * completed ride rather than left as an open ride nobody can close.
     */
    public function end(TaxiRide $ride, string $endedBy = 'passenger', ?Coordinate $at = null): TaxiRide
    {
        if (! $ride->isOpen()) {
            return $ride;
        }

        return DB::transaction(function () use ($ride, $endedBy, $at): TaxiRide {
            $locked = TaxiRide::whereKey($ride->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                return $locked;
            }

            $locked->forceFill([
                'ended_at' => now(),
                'ended_by' => $endedBy,
                'duration_seconds' => (int) $locked->started_at->diffInSeconds(now()),
                'end_lat' => $at?->lat ?? $locked->end_lat,
                'end_lng' => $at?->lng ?? $locked->end_lng,
                'status' => TaxiRideStatus::Completed,
            ])->save();

            if ($locked->service_type === TaxiServiceType::Meter) {
                $this->closeMeteredRide($locked);
            }

            $shift = TaxiShift::whereKey($locked->taxi_shift_id)->lockForUpdate()->first();

            if ($shift !== null) {
                $shift->forceFill([
                    'alighting_count' => $shift->alighting_count + 1,
                    'onboard_count' => max(0, $shift->onboard_count - 1),
                ])->save();

                if ($shift->isOpen()) {
                    $this->live->publish($shift->fresh(['taxi', 'line']));
                }
            }

            $fresh = $locked->fresh();

            TaxiRideEnded::dispatch(
                $fresh->taxi_shift_id,
                $fresh->user_id,
                $fresh->uuid,
                $fresh->fare_amount,
                $shift?->onboard_count ?? 0,
                $fresh->status->value,
            );

            return $fresh;
        }, 3);
    }

    public function activeRideFor(User $user): ?TaxiRide
    {
        return TaxiRide::with(['taxi', 'line', 'tariff', 'driver.user'])
            ->where('user_id', $user->id)
            ->open()
            ->latest('started_at')
            ->first();
    }

    /** Money a passenger still owes from a metered ride their wallet could not cover. */
    public function outstandingFor(User $user): int
    {
        return (int) TaxiRide::where('user_id', $user->id)
            ->unpaid()
            ->sum('outstanding_amount');
    }

    /**
     * Settle an outstanding fare once the passenger has topped up.
     *
     * Kept as its own path rather than folded into the top-up flow: paying a
     * debt is the passenger's decision, and a balance silently swept the moment
     * it arrives is how people stop trusting a wallet.
     */
    public function settleOutstanding(TaxiRide $ride): TaxiRide
    {
        if ($ride->status !== TaxiRideStatus::Unpaid || $ride->outstanding_amount <= 0) {
            return $ride;
        }

        $this->settle($ride, $ride->outstanding_amount, $ride->user);

        $ride->forceFill([
            'status' => TaxiRideStatus::Completed,
            'outstanding_amount' => 0,
        ])->save();

        return $ride->fresh();
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function priceFor(User $user, TaxiShift $shift): TaxiFareQuote
    {
        return match ($shift->service_type) {
            TaxiServiceType::Line => $this->tariffs->quoteLine(
                $shift->line ?? throw DomainException::make('line_required_for_line_service', 422),
            ),

            TaxiServiceType::Charter => $this->tariffs->quoteCharter(
                $shift->pendingCharterAmount()
                    ?? throw DomainException::make('no_charter_amount_set', 422, [
                        'taxi_number' => $shift->taxi?->taxi_number,
                    ]),
            ),

            TaxiServiceType::Meter => $this->meterStartQuote($user, $shift),
        };
    }

    /**
     * A metered ride's "quote" is not a price — it is the tariff the passenger
     * is agreeing to be billed by, plus the confirmation that their wallet can
     * stand the start of it.
     */
    private function meterStartQuote(User $user, TaxiShift $shift): TaxiFareQuote
    {
        $tariff = $this->tariffs->resolve($shift->city_id, TaxiServiceType::Meter);

        if ($tariff === null) {
            throw DomainException::make('no_taxi_tariff_configured', 422, [
                'city_id' => $shift->city_id,
                'service_type' => TaxiServiceType::Meter->value,
            ]);
        }

        $required = $this->tariffs->requiredStartingBalance($tariff);
        $wallet = $this->wallets->forUser($user);

        if (! $wallet->canSpend($required)) {
            throw new InsufficientFundsException($required, $wallet->availableBalance());
        }

        return new TaxiFareQuote(
            amount: 0,
            serviceType: TaxiServiceType::Meter,
            tariff: $tariff,
            breakdown: [
                'kind' => 'meter_start',
                'tariff_name' => $tariff->name,
                'base_fare' => (int) $tariff->base_fare,
                'per_km_fare' => (int) $tariff->per_km_fare,
                'per_minute_waiting_fare' => (int) $tariff->per_minute_waiting_fare,
                'minimum_fare' => (int) $tariff->minimum_fare,
                'required_balance' => $required,
            ],
        );
    }

    private function closeMeteredRide(TaxiRide $ride): void
    {
        $quote = $this->meter->currentQuote($ride);

        if ($quote === null) {
            // No tariff left to price by. Recording a zero fare is honest; a
            // guessed one is not.
            $ride->forceFill(['fare_breakdown' => ['kind' => 'meter_unpriced']])->save();

            return;
        }

        $ride->forceFill(['fare_breakdown' => $quote->breakdown])->save();

        try {
            $this->settle($ride, $quote->amount, $ride->user);
        } catch (InsufficientFundsException) {
            // The ride is over either way. The debt is recorded against it and
            // blocks the next ride, which is a far better outcome than a ride
            // that can never be closed.
            //
            // `fare_amount` stays at zero on purpose: it means money that
            // actually moved, and every report, every earnings figure and every
            // payout is built on that meaning. What was owed lives in
            // `outstanding_amount` and in the breakdown until it is paid.
            $ride->forceFill([
                'status' => TaxiRideStatus::Unpaid,
                'outstanding_amount' => $quote->amount,
            ])->save();
        }
    }

    /** Move the money: passenger wallet to driver wallet, commission split out. */
    private function settle(TaxiRide $ride, int $amount, User $payer): void
    {
        if ($amount <= 0) {
            return;
        }

        $taxi = $ride->taxi;
        $driverUser = $ride->driver?->user;

        if ($driverUser === null) {
            throw DomainException::make('taxi_driver_has_no_account', 500);
        }

        $commission = intdiv($amount * $taxi->commissionBps(), 10_000);

        $wallet = $this->wallets->forUser($payer);
        $driverWallet = $this->wallets->forUser($driverUser);

        $transaction = $this->wallets->chargeTaxiFare(
            payer: $wallet,
            driverWallet: $driverWallet,
            amount: $amount,
            commission: $commission,
            // Idempotent on the scan: a retried confirmation with the same
            // token can never post the fare twice.
            idempotencyKey: 'taxi:'.$ride->uuid.':'.($ride->token_nonce ?? 'settle'),
            subject: $ride,
            initiatedBy: $payer,
            metadata: [
                'taxi_number' => $taxi->taxi_number,
                'service_type' => $ride->service_type->value,
                'ride_uuid' => $ride->uuid,
            ],
        );

        $ride->forceFill([
            'fare_amount' => $amount,
            'commission_amount' => $commission,
            'wallet_transaction_id' => $transaction->id,
            'outstanding_amount' => 0,
        ])->save();

        $shift = $ride->shift;

        $shift?->forceFill([
            'gross_minor' => $shift->gross_minor + $amount,
            'commission_minor' => $shift->commission_minor + $commission,
            'net_minor' => $shift->net_minor + ($amount - $commission),
        ])->save();
    }

    private function openShiftFor(Taxi $taxi): TaxiShift
    {
        $shift = TaxiShift::with(['line', 'taxi', 'driver.user'])
            ->where('taxi_id', $taxi->id)
            ->open()
            ->latest('started_at')
            ->first();

        if ($shift === null) {
            throw DomainException::make('taxi_not_in_service', 422, [
                'taxi_number' => $taxi->taxi_number,
            ]);
        }

        return $shift;
    }

    private function assertPassengerMayRide(User $user, Taxi $taxi): void
    {
        if ($user->city_id !== null && $user->city_id !== $taxi->city_id) {
            throw DomainException::make('city_mismatch', 422);
        }

        $open = TaxiRide::where('user_id', $user->id)->open()->first();

        if ($open !== null) {
            throw DomainException::make('ride_already_in_progress', 409, [
                'ride_uuid' => $open->uuid,
            ]);
        }

        $outstanding = $this->outstandingFor($user);

        if ($outstanding > 0) {
            throw DomainException::make('outstanding_taxi_fare', 402, [
                'outstanding' => $outstanding,
            ]);
        }
    }

    /**
     * A scan from across the city is not a scan of that car.
     *
     * Only checked when the passenger's phone offers a position: refusing a
     * ride because somebody denied location permission would punish the wrong
     * thing, and the QR's own freshness is the primary defence.
     */
    private function assertNearTaxi(Taxi $taxi, ?Coordinate $position): void
    {
        if ($position === null) {
            return;
        }

        $taxiPosition = $taxi->lastPosition();

        if ($taxiPosition === null) {
            return;
        }

        $distance = Distance::between($position, $taxiPosition);
        $max = (int) config('taxi.scan.max_distance_meters', 150);

        if ($distance > $max) {
            throw DomainException::make('too_far_from_taxi', 422, [
                'distance_meters' => (int) round($distance),
                'maximum' => $max,
            ]);
        }
    }

    /**
     * The client may echo a price back; it may never set one.
     *
     * A metered ride has no up-front amount at all, so anything sent with one
     * is a client that has misunderstood the mode.
     */
    private function assertAmountMatches(TaxiFareQuote $quote, ?int $acceptedAmount): void
    {
        if (! $quote->serviceType->isPricedUpFront()) {
            return;
        }

        if ($acceptedAmount === null) {
            throw DomainException::make('amount_confirmation_required', 422, [
                'amount' => $quote->amount,
            ]);
        }

        if ($acceptedAmount !== $quote->amount) {
            throw DomainException::make('amount_mismatch', 409, [
                'quoted' => $quote->amount,
                'accepted' => $acceptedAmount,
            ]);
        }
    }

    /**
     * How exclusive one displayed code is.
     *
     * A charter or a metered car takes one hire at a time, so its code is
     * single-use: two people must not both claim the same car. A shared line
     * taxi fills four seats at a terminal, and making passengers queue for the
     * code to rotate every thirty seconds would be an artificial obstacle — so
     * there the claim is per passenger, which still stops one person paying
     * twice while letting a full car board together.
     */
    private function nonceScopeFor(TaxiShift $shift, User $user): string
    {
        return $shift->service_type === TaxiServiceType::Line
            ? TaxiQrService::NONCE_SCOPE.':line:'.$user->id
            : TaxiQrService::NONCE_SCOPE;
    }

    private function assertNotRateLimited(User $user): void
    {
        $key = 'taxi-ride:'.$user->id;
        $perMinute = (int) config('wallet.fraud.max_payments_per_minute');

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw DomainException::make('too_many_attempts', 429, [
                'retry_after' => RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    private function positionFrom(array $context): ?Coordinate
    {
        return isset($context['lat'], $context['lng'])
            ? new Coordinate((float) $context['lat'], (float) $context['lng'])
            : null;
    }
}
