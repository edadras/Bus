<?php

namespace App\Domain\Taxi\Services;

use App\Domain\Network\Models\City;
use App\Domain\Taxi\DTO\TaxiFareQuote;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Models\TaxiLine;
use App\Domain\Taxi\Models\TaxiTariff;
use App\Support\Exceptions\DomainException;
use Carbon\CarbonInterface;

/**
 * Prices a taxi ride.
 *
 * Nothing here is hard coded. A line's fare belongs to the line, a charter is
 * the driver's own figure bounded by a configured ceiling, and a metered fare
 * comes out of the city's tariff table — which is also where a night rate or a
 * peak multiplier lives, as another row rather than another branch.
 */
class TaxiTariffEngine
{
    /**
     * The tariff in force for a city and mode right now.
     *
     * Time windows are applied in PHP rather than SQL because a night tariff
     * legitimately wraps midnight, and expressing that in a WHERE clause that
     * also has to be portable is how these things quietly stop matching at
     * 23:00 one day.
     */
    public function resolve(City|int $city, TaxiServiceType $type, ?CarbonInterface $at = null): ?TaxiTariff
    {
        $at ??= now();
        $cityId = $city instanceof City ? $city->id : $city;

        return TaxiTariff::query()
            ->where('city_id', $cityId)
            ->where('service_type', $type->value)
            ->active()
            ->orderByDesc('priority')
            ->get()
            ->first(fn (TaxiTariff $tariff) => $tariff->coversTime($at));
    }

    /** A shared line is priced by the line, not by distance. */
    public function quoteLine(TaxiLine $line): TaxiFareQuote
    {
        if (! $line->is_active) {
            throw DomainException::make('taxi_line_inactive', 422, ['line' => $line->code]);
        }

        return new TaxiFareQuote(
            amount: (int) $line->flat_fare,
            serviceType: TaxiServiceType::Line,
            breakdown: [
                'kind' => 'flat',
                'line_code' => $line->code,
                'line_name' => $line->name,
                'flat_fare' => (int) $line->flat_fare,
            ],
        );
    }

    /**
     * A charter is whatever the driver said, inside sane bounds.
     *
     * The ceiling is not paternalism: without it a mistyped figure — an extra
     * zero — becomes a charge the passenger confirms without reading.
     */
    public function quoteCharter(int $amount): TaxiFareQuote
    {
        $max = (int) config('taxi.charter.max_amount');

        if ($amount <= 0) {
            throw DomainException::make('amount_must_be_positive', 422, ['amount' => $amount]);
        }

        if ($amount > $max) {
            throw DomainException::make('charter_amount_too_large', 422, [
                'amount' => $amount,
                'maximum' => $max,
            ]);
        }

        return new TaxiFareQuote(
            amount: $amount,
            serviceType: TaxiServiceType::Charter,
            breakdown: ['kind' => 'driver_quoted', 'amount' => $amount],
        );
    }

    /**
     * Price a completed meter run.
     *
     * Distance and waiting are billed separately because they are separate
     * things: a car stuck at a light has covered no ground, and billing that
     * time as distance is exactly the complaint that destroys trust in a meter.
     */
    public function quoteMeter(TaxiTariff $tariff, int $distanceMeters, int $waitingSeconds): TaxiFareQuote
    {
        $kilometres = $distanceMeters / 1000;
        $waitingMinutes = $waitingSeconds / 60;

        $distanceComponent = (int) round($kilometres * $tariff->per_km_fare);
        $waitingComponent = (int) round($waitingMinutes * $tariff->per_minute_waiting_fare);

        $subtotal = $tariff->base_fare + $distanceComponent + $waitingComponent;
        $multiplied = (int) round($subtotal * $tariff->multiplier);

        $total = max($multiplied, (int) $tariff->minimum_fare);
        $cappedAt = null;

        if ($tariff->maximum_fare !== null && $total > $tariff->maximum_fare) {
            $total = (int) $tariff->maximum_fare;
            $cappedAt = (int) $tariff->maximum_fare;
        }

        return new TaxiFareQuote(
            amount: $total,
            serviceType: TaxiServiceType::Meter,
            tariff: $tariff,
            breakdown: [
                'kind' => 'metered',
                'tariff_name' => $tariff->name,
                'base_fare' => (int) $tariff->base_fare,
                'distance_meters' => $distanceMeters,
                'per_km_fare' => (int) $tariff->per_km_fare,
                'distance_component' => $distanceComponent,
                'waiting_seconds' => $waitingSeconds,
                'per_minute_waiting_fare' => (int) $tariff->per_minute_waiting_fare,
                'waiting_component' => $waitingComponent,
                'subtotal' => $subtotal,
                'multiplier' => (float) $tariff->multiplier,
                'minimum_fare' => (int) $tariff->minimum_fare,
                'minimum_applied' => $multiplied < (int) $tariff->minimum_fare,
                'capped_at' => $cappedAt,
                'total' => $total,
            ],
        );
    }

    /**
     * The balance a wallet must hold before a meter may start.
     *
     * A metered ride is the only product whose price is unknown at the kerb, so
     * this is the one place the risk can be caught. Refusing to start is a
     * small annoyance; stopping the car at the destination to argue about an
     * empty wallet is not.
     */
    public function requiredStartingBalance(TaxiTariff $tariff): int
    {
        $multiple = max(1, (int) config('taxi.meter.minimum_start_balance_multiple', 3));
        $floor = max((int) $tariff->minimum_fare, (int) $tariff->base_fare);

        return $floor * $multiple;
    }
}
