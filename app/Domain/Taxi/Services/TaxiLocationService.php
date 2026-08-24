<?php

namespace App\Domain\Taxi\Services;

use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Models\TaxiRide;
use App\Domain\Taxi\Models\TaxiShift;
use App\Support\Geo\Coordinate;
use Carbon\CarbonInterface;

/**
 * Where the taxi is, and what that does.
 *
 * One report from the driver's device feeds three things at once: the car's
 * last known position, its dot on the live map, and — if a meter is running —
 * the fare. They share a report because they must share a truth: a passenger
 * watching the meter climb and a dispatcher watching the car move have to be
 * looking at the same readings.
 */
class TaxiLocationService
{
    public function __construct(
        private readonly TaxiLiveService $live,
        private readonly TaxiMeterService $meter,
    ) {}

    /**
     * @return array{accepted: bool, ride: TaxiRide|null, fare: array<string, mixed>|null, next_report_in: int}
     */
    public function report(
        TaxiShift $shift,
        Coordinate $position,
        ?float $speedKmh = null,
        ?float $accuracy = null,
        ?CarbonInterface $recordedAt = null,
    ): array {
        $recordedAt ??= now();
        $taxi = $shift->taxi;

        $taxi->forceFill([
            'last_lat' => $position->lat,
            'last_lng' => $position->lng,
            'last_ping_at' => $recordedAt,
        ])->save();

        $this->live->publish($shift, $position, $speedKmh);

        $ride = $shift->service_type === TaxiServiceType::Meter
            ? $shift->rides()->open()->with('tariff')->latest('started_at')->first()
            : null;

        $fare = null;
        $accepted = true;

        if ($ride !== null) {
            $sample = $this->meter->accumulate($ride, $position, $speedKmh, $accuracy, $recordedAt);
            $accepted = ! $sample->is_discarded;

            $ride->refresh();
            $fare = $this->meter->currentQuote($ride)?->toArray();
        }

        return [
            'accepted' => $accepted,
            'ride' => $ride,
            'fare' => $fare,
            // A moving car reports often; a parked one need not. The interval is
            // advice, not enforcement — the server rejects what it must anyway.
            'next_report_in' => $ride !== null ? 5 : 15,
        ];
    }
}
