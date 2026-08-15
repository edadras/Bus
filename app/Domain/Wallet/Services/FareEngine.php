<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Network\Models\BusLine;
use App\Domain\Operations\Models\Trip;
use App\Domain\Wallet\DTO\FareQuote;
use App\Domain\Wallet\Models\FareRule;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Prices a ride. No fare is ever hard coded: rules live in `fare_rules` and are
 * editable by finance staff. Selection is deliberate and explainable —
 * candidates are filtered in SQL, time predicates are applied in PHP, then the
 * winner is the highest (priority, specificity) pair. The returned quote
 * carries its breakdown so a disputed charge can be reconstructed exactly.
 */
class FareEngine
{
    private const CACHE_TTL = 300;

    public function quoteForTrip(
        Trip $trip,
        User $passenger,
        ?CarbonInterface $at = null,
        ?int $distanceMeters = null,
    ): FareQuote {
        $at ??= now();

        $rule = $this->resolveRule(
            cityId: $trip->city_id,
            context: 'bus',
            at: $at,
            lineId: $trip->bus_line_id,
            passengerType: $this->passengerTypeFor($passenger),
        );

        if ($rule === null) {
            throw DomainException::make('no_fare_rule_configured', 422, [
                'city_id' => $trip->city_id,
                'line_id' => $trip->bus_line_id,
            ]);
        }

        return $this->price($rule, $distanceMeters);
    }

    public function quoteForLine(BusLine $line, ?User $passenger = null, ?CarbonInterface $at = null): FareQuote
    {
        $at ??= now();

        $rule = $this->resolveRule(
            cityId: $line->city_id,
            context: 'bus',
            at: $at,
            lineId: $line->id,
            passengerType: $passenger !== null ? $this->passengerTypeFor($passenger) : null,
        );

        if ($rule === null) {
            throw DomainException::make('no_fare_rule_configured', 422, ['line_id' => $line->id]);
        }

        return $this->price($rule, null);
    }

    /**
     * Merchant charges are operator-entered amounts, so the "fare" is simply
     * the requested figure validated against the merchant's ceiling.
     */
    public function quoteForMerchant(Merchant $merchant, int $requestedAmount): FareQuote
    {
        if ($requestedAmount <= 0) {
            throw DomainException::make('amount_must_be_positive', 422);
        }

        if ($merchant->max_transaction_amount !== null && $requestedAmount > $merchant->max_transaction_amount) {
            throw DomainException::make('amount_above_merchant_limit', 422, [
                'maximum' => $merchant->max_transaction_amount,
            ]);
        }

        $commission = $merchant->commissionOn($requestedAmount);

        return new FareQuote(
            amount: $requestedAmount,
            rule: null,
            currency: config('wallet.currency'),
            breakdown: [
                'gross' => $requestedAmount,
                'commission' => $commission,
                'commission_bps' => $merchant->commission_bps,
                'net' => $requestedAmount - $commission,
            ],
        );
    }

    private function price(FareRule $rule, ?int $distanceMeters): FareQuote
    {
        $base = $rule->base_fare;

        $distanceComponent = 0;
        if ($rule->per_km_fare > 0 && $distanceMeters !== null && $distanceMeters > 0) {
            $distanceComponent = (int) round($rule->per_km_fare * $distanceMeters / 1000);
        }

        $subtotal = $base + $distanceComponent;
        $afterMultiplier = (int) round($subtotal * $rule->multiplier);

        $amount = $afterMultiplier;

        if ($rule->min_fare !== null) {
            $amount = max($amount, $rule->min_fare);
        }

        if ($rule->max_fare !== null) {
            $amount = min($amount, $rule->max_fare);
        }

        return new FareQuote(
            amount: max(0, $amount),
            rule: $rule,
            currency: config('wallet.currency'),
            breakdown: array_filter([
                'base_fare' => $base,
                'distance_component' => $distanceComponent ?: null,
                'distance_meters' => $distanceMeters,
                'multiplier' => $rule->multiplier !== 1.0 ? $rule->multiplier : null,
                'subtotal' => $subtotal !== $amount ? $subtotal : null,
                'min_fare_applied' => $rule->min_fare !== null && $afterMultiplier < $rule->min_fare ? $rule->min_fare : null,
                'max_fare_applied' => $rule->max_fare !== null && $afterMultiplier > $rule->max_fare ? $rule->max_fare : null,
                'total' => $amount,
            ], fn ($v) => $v !== null),
        );
    }

    public function resolveRule(
        int $cityId,
        string $context,
        CarbonInterface $at,
        ?int $lineId = null,
        ?string $passengerType = null,
        ?int $fromZoneId = null,
        ?int $toZoneId = null,
    ): ?FareRule {
        $candidates = $this->candidatesFor($cityId, $context);

        $matching = $candidates
            ->filter(fn (FareRule $rule) => $this->matchesDimension($rule->bus_line_id, $lineId))
            ->filter(fn (FareRule $rule) => $this->matchesDimension($rule->passenger_type, $passengerType))
            ->filter(fn (FareRule $rule) => $this->matchesDimension($rule->from_zone_id, $fromZoneId))
            ->filter(fn (FareRule $rule) => $this->matchesDimension($rule->to_zone_id, $toZoneId))
            ->filter(fn (FareRule $rule) => $rule->appliesAt($at));

        return $matching
            ->sortByDesc(fn (FareRule $rule) => [$rule->priority, $rule->specificity()])
            ->first();
    }

    /**
     * A rule dimension either targets a specific value or is a wildcard (null).
     * A rule that names a line must not price a different line.
     */
    private function matchesDimension(mixed $ruleValue, mixed $contextValue): bool
    {
        return $ruleValue === null || $ruleValue == $contextValue;
    }

    /** @return Collection<int, FareRule> */
    private function candidatesFor(int $cityId, string $context): Collection
    {
        return Cache::remember(
            "fare_rules:$cityId:$context",
            self::CACHE_TTL,
            fn () => FareRule::query()
                ->where('city_id', $cityId)
                ->where('context', $context)
                ->where('is_active', true)
                ->orderByDesc('priority')
                ->get(),
        );
    }

    public function flushCache(int $cityId): void
    {
        foreach (['bus', 'merchant'] as $context) {
            Cache::forget("fare_rules:$cityId:$context");
        }
    }

    /** Concession category, taken from the passenger's profile preferences. */
    private function passengerTypeFor(User $user): string
    {
        return (string) ($user->preferences['passenger_type'] ?? 'regular');
    }
}
