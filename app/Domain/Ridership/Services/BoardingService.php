<?php

namespace App\Domain\Ridership\Services;

use App\Domain\Fleet\DTO\QrToken;
use App\Domain\Fleet\Services\BusQrService;
use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Identity\Models\User;
use App\Domain\Operations\Enums\TripEventType;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Services\RouteMatcher;
use App\Domain\Operations\Services\TripEventRecorder;
use App\Domain\Operations\Services\TripService;
use App\Domain\Ridership\Enums\PassengerTripStatus;
use App\Domain\Ridership\Events\PassengerBoarded;
use App\Domain\Ridership\Models\PassengerBoarding;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Domain\Wallet\Services\FareEngine;
use App\Domain\Wallet\Services\WalletService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\InsufficientFundsException;
use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Boarding a bus: scan, verify, charge, seat.
 *
 * This is the most attacked path in the system, so the checks are ordered
 * cheapest-and-most-decisive first, and the money moves last:
 *
 *   1. rate limit the caller                (cheap, stops brute force)
 *   2. verify the QR signature and freshness (cryptographic, stops copies)
 *   3. find the bus's active trip            (stops paying a parked bus)
 *   4. reject a duplicate/cooldown boarding  (stops double charging)
 *   5. proximity check, if a position given  (stops remote boarding)
 *   6. price the fare                        (never hard coded)
 *   7. claim the nonce, then debit the wallet, then seat the passenger — all
 *      inside one database transaction, so a failure anywhere leaves no charge
 *      and no phantom passenger.
 */
class BoardingService
{
    public function __construct(
        private readonly BusQrService $qr,
        private readonly QrTokenService $tokens,
        private readonly TripService $trips,
        private readonly FareEngine $fares,
        private readonly WalletService $wallets,
        private readonly RouteMatcher $matcher,
        private readonly TripEventRecorder $events,
    ) {}

    /**
     * @param  array{lat?: float, lng?: float, device?: string, ip?: string}  $context
     * @return array{passenger_trip: PassengerTrip, trip: Trip, fare: int, balance: int}
     */
    public function board(User $user, string $rawToken, array $context = []): array
    {
        $this->assertNotRateLimited($user);

        $resolved = $this->qr->resolveScan($rawToken);
        /** @var QrToken $token */
        $token = $resolved['token'];
        $bus = $resolved['bus'];

        $trip = $this->trips->activeTripFor($bus);

        if ($trip === null) {
            throw DomainException::make('no_active_trip', 422, ['bus_number' => $bus->bus_number]);
        }

        if (! $trip->status->acceptsBoarding()) {
            throw DomainException::make('trip_not_accepting_boarding', 422, [
                'status' => $trip->status->value,
            ]);
        }

        $this->assertNotAlreadyAboard($user, $trip);
        $this->assertNotInCooldown($user, $trip);

        $position = $this->positionFrom($context);
        $distanceToBus = $this->assertNearBus($trip, $position);

        $quote = $this->fares->quoteForTrip($trip, $user);
        $wallet = $this->wallets->forUser($user);

        if (! $wallet->canSpend($quote->amount)) {
            throw new InsufficientFundsException(
                $quote->amount,
                $wallet->availableBalance(),
            );
        }

        // Claiming the nonce before the transaction makes the token single-use
        // even under concurrency; if anything below fails we release it so the
        // passenger is not forced to wait for a fresh code.
        $this->tokens->consumeNonce($token);

        try {
            return DB::transaction(function () use ($user, $trip, $bus, $token, $resolved, $quote, $wallet, $position, $distanceToBus, $context) {
                // Re-read under lock: two taps in flight must not both seat.
                $locked = Trip::whereKey($trip->id)->lockForUpdate()->first();

                $duplicate = PassengerTrip::where('user_id', $user->id)
                    ->where('trip_id', $trip->id)
                    ->open()
                    ->exists();

                if ($duplicate) {
                    throw DomainException::make('already_on_this_bus', 409);
                }

                $boardingStop = $position !== null && $locked->route !== null
                    ? $this->matcher->stopAt($locked->route, $position)?->bus_stop_id
                    : $locked->current_stop_id;

                $passengerTrip = PassengerTrip::create([
                    'user_id' => $user->id,
                    'trip_id' => $locked->id,
                    'bus_id' => $bus->id,
                    'city_id' => $locked->city_id,
                    'bus_line_id' => $locked->bus_line_id,
                    'route_id' => $locked->route_id,
                    'status' => PassengerTripStatus::Active,
                    'boarding_stop_id' => $boardingStop,
                    'boarded_at' => now(),
                    'fare_amount' => $quote->amount,
                    'fare_rule_id' => $quote->rule?->id,
                ]);

                $transaction = $quote->amount > 0
                    ? $this->wallets->chargeFare(
                        wallet: $wallet,
                        amount: $quote->amount,
                        // Idempotent on the token: a retried request with the
                        // same scan can never post the fare twice.
                        idempotencyKey: 'fare:'.$token->publicId.':'.$token->nonce.':'.$user->id,
                        subject: $passengerTrip,
                        initiatedBy: $user,
                        metadata: [
                            'trip_uuid' => $locked->uuid,
                            'line' => $locked->line?->code,
                            'fare_breakdown' => $quote->breakdown,
                        ],
                    )
                    : null;

                $passengerTrip->forceFill(['wallet_transaction_id' => $transaction?->id])->save();

                PassengerBoarding::create([
                    'passenger_trip_id' => $passengerTrip->id,
                    'trip_id' => $locked->id,
                    'user_id' => $user->id,
                    'bus_stop_id' => $boardingStop,
                    'bus_qr_code_id' => $resolved['qr']->id,
                    'token_nonce' => $token->nonce,
                    'lat' => $position?->lat,
                    'lng' => $position?->lng,
                    'distance_to_bus_meters' => $distanceToBus,
                    'device_fingerprint' => $context['device'] ?? null,
                    'client_ip' => $context['ip'] ?? null,
                    'boarded_at' => now(),
                ]);

                $count = $locked->passenger_count + 1;

                $locked->forceFill([
                    'passenger_count' => $count,
                    'peak_passenger_count' => max($locked->peak_passenger_count, $count),
                    'boarding_count' => $locked->boarding_count + 1,
                    'revenue_minor' => $locked->revenue_minor + $quote->amount,
                ])->save();

                $locked->shift?->forceFill([
                    'passenger_count' => ($locked->shift->passenger_count ?? 0) + 1,
                    'revenue_minor' => ($locked->shift->revenue_minor ?? 0) + $quote->amount,
                ])->save();

                $this->events->record($locked, TripEventType::PassengerBoarded, [
                    'stop_id' => $boardingStop,
                    'passenger_count' => $count,
                ]);

                PassengerBoarded::dispatch(
                    $locked->id,
                    $locked->city_id,
                    $count,
                    $passengerTrip->uuid,
                );

                return [
                    'passenger_trip' => $passengerTrip,
                    'trip' => $locked,
                    'fare' => $quote->amount,
                    'fare_breakdown' => $quote->breakdown,
                    'balance' => $wallet->fresh()->balance,
                ];
            }, 3);
        } catch (\Throwable $e) {
            $this->tokens->releaseNonce($token);

            throw $e;
        }
    }

    private function assertNotRateLimited(User $user): void
    {
        $key = 'boarding:'.$user->id;
        $perMinute = (int) config('wallet.fraud.max_payments_per_minute');

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            throw DomainException::make('too_many_attempts', 429, [
                'retry_after' => RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    private function assertNotAlreadyAboard(User $user, Trip $trip): void
    {
        $openElsewhere = PassengerTrip::where('user_id', $user->id)
            ->open()
            ->where('trip_id', '!=', $trip->id)
            ->first();

        if ($openElsewhere !== null) {
            throw DomainException::make('ride_already_in_progress', 409, [
                'passenger_trip_uuid' => $openElsewhere->uuid,
            ]);
        }

        $sameTrip = PassengerTrip::where('user_id', $user->id)
            ->where('trip_id', $trip->id)
            ->open()
            ->exists();

        if ($sameTrip) {
            throw DomainException::make('already_on_this_bus', 409);
        }
    }

    /**
     * Stop a passenger who just alighted from being charged again by a stray
     * second scan of the same bus.
     */
    private function assertNotInCooldown(User $user, Trip $trip): void
    {
        $cooldown = (int) config('transit.ridership.reboard_cooldown_seconds');

        if ($cooldown <= 0) {
            return;
        }

        $recent = PassengerTrip::where('user_id', $user->id)
            ->where('trip_id', $trip->id)
            ->where('boarded_at', '>=', now()->subSeconds($cooldown))
            ->exists();

        if ($recent) {
            throw DomainException::make('reboard_cooldown', 429, ['cooldown_seconds' => $cooldown]);
        }
    }

    private function positionFrom(array $context): ?Coordinate
    {
        return isset($context['lat'], $context['lng'])
            ? new Coordinate((float) $context['lat'], (float) $context['lng'])
            : null;
    }

    /**
     * If the client supplied a position, it must be plausibly on the bus. A
     * missing position is tolerated (indoor GPS is unreliable and refusing
     * fares over it would be worse than the residual risk) — the signed,
     * rotating token remains the primary proof of presence.
     */
    private function assertNearBus(Trip $trip, ?Coordinate $position): ?float
    {
        $busPosition = $trip->position();

        if ($position === null || $busPosition === null) {
            return null;
        }

        $distance = Distance::between($position, $busPosition);
        $max = (float) config('transit.ridership.boarding_proximity_meters');

        if ($distance > $max) {
            throw DomainException::make('too_far_from_bus', 422, [
                'distance_meters' => (int) round($distance),
                'maximum_meters' => (int) $max,
            ]);
        }

        return round($distance, 2);
    }
}
