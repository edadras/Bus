<?php

namespace App\Domain\Taxi\Services;

use App\Domain\Taxi\DTO\TaxiFareQuote;
use App\Domain\Taxi\Models\TaxiMeterSample;
use App\Domain\Taxi\Models\TaxiRide;
use App\Support\Geo\Coordinate;
use App\Support\Geo\Distance;
use Carbon\CarbonInterface;

/**
 * The taxi meter.
 *
 * Distance comes from the car's own reports, never the passenger's phone: the
 * phone is the party being billed, and a meter a passenger can influence is not
 * a meter. Each accepted reading contributes a distance increment and a slice
 * of elapsed time, classified as running or waiting, and every reading is kept
 * — including the ones thrown away and the reason — so a disputed fare has an
 * answer rather than an assertion.
 *
 * Four things are refused, each for a concrete reason:
 *
 *   too soon      a client reporting every second would inflate the waiting
 *                 clock with samples the car could not have moved between
 *   too vague     a 200 m accuracy circle can invent a kilometre over a few
 *                 readings
 *   too fast      no car in a city covers that ground; it is a bad fix or a
 *                 spoof, and either way it is not distance travelled
 *   too late      after a long silence nobody can say whether the car was
 *                 waiting or driving, so the gap is billed as neither
 */
class TaxiMeterService
{
    public function __construct(private readonly TaxiTariffEngine $tariffs) {}

    /**
     * Fold one reading into a running ride.
     *
     * Returns the stored sample, discarded or not, so the caller can tell the
     * driver's device that the reading arrived even when it changed nothing.
     */
    public function accumulate(
        TaxiRide $ride,
        Coordinate $position,
        ?float $speedKmh = null,
        ?float $accuracy = null,
        ?CarbonInterface $recordedAt = null,
    ): TaxiMeterSample {
        $recordedAt ??= now();
        $previous = $ride->meterSamples()->where('is_discarded', false)->latest('recorded_at')->first();

        $elapsed = $previous === null
            ? 0
            : max(0, (int) $previous->recorded_at->diffInSeconds($recordedAt));

        $discardReason = $this->discardReason($previous, $elapsed, $accuracy);

        if ($discardReason !== null) {
            return $this->store($ride, $position, $speedKmh, $accuracy, $recordedAt, 0, 0, false, $discardReason);
        }

        $distance = $previous === null
            ? 0.0
            : Distance::between(
                new Coordinate((float) $previous->lat, (float) $previous->lng),
                $position,
            );

        // Implausible ground speed between two fixes is not travel. The reading
        // is recorded and discarded, and deliberately does not become the new
        // anchor: a single bad fix that jumps and comes back would otherwise
        // stall the meter, since every later reading would jump implausibly
        // back towards the truth.
        $impliedSpeed = $elapsed > 0 ? ($distance / $elapsed) * 3.6 : 0.0;

        if ($impliedSpeed > (float) config('taxi.meter.max_plausible_speed_kmh', 140)) {
            return $this->store($ride, $position, $speedKmh, $accuracy, $recordedAt, 0, 0, false, 'implausible_speed');
        }

        $waitingThreshold = (float) ($ride->tariff?->waiting_speed_kmh ?? 5);
        $isWaiting = $this->isWaiting($speedKmh, $impliedSpeed, $waitingThreshold);

        $sample = $this->store(
            $ride,
            $position,
            $speedKmh,
            $accuracy,
            $recordedAt,
            (int) round($distance),
            $elapsed,
            $isWaiting,
            null,
        );

        $ride->forceFill([
            'distance_meters' => $ride->distance_meters + (int) round($distance),
            'waiting_seconds' => $ride->waiting_seconds + ($isWaiting ? $elapsed : 0),
            'duration_seconds' => (int) $ride->started_at->diffInSeconds($recordedAt),
            'end_lat' => $position->lat,
            'end_lng' => $position->lng,
        ])->save();

        return $sample;
    }

    /**
     * What the meter reads right now.
     *
     * The passenger sees this while the car is moving, which is the whole point
     * of a meter: a number that only appears at the destination is a surprise,
     * not a price.
     */
    public function currentQuote(TaxiRide $ride): ?TaxiFareQuote
    {
        $tariff = $ride->tariff;

        if ($tariff === null) {
            return null;
        }

        return $this->tariffs->quoteMeter($tariff, $ride->distance_meters, $ride->waiting_seconds);
    }

    /** Has this ride run past the point where it is a fault rather than a fare? */
    public function hasOverrun(TaxiRide $ride): bool
    {
        $limit = (int) config('taxi.meter.max_duration_minutes', 240);

        return $ride->started_at->diffInMinutes(now()) > $limit;
    }

    private function discardReason(?TaxiMeterSample $previous, int $elapsed, ?float $accuracy): ?string
    {
        if ($accuracy !== null && $accuracy > (float) config('taxi.meter.max_accuracy_meters', 60)) {
            return 'poor_accuracy';
        }

        if ($previous === null) {
            return null;
        }

        if ($elapsed < (int) config('taxi.meter.min_sample_interval_seconds', 5)) {
            return 'too_frequent';
        }

        if ($elapsed > (int) config('taxi.meter.max_sample_gap_seconds', 120)) {
            return 'gap_too_long';
        }

        return null;
    }

    /**
     * Waiting is decided from the reported speed when the device gives one and
     * from the ground covered otherwise, because a stationary car often reports
     * no speed at all rather than zero.
     */
    private function isWaiting(?float $reportedSpeed, float $impliedSpeed, float $threshold): bool
    {
        $speed = $reportedSpeed ?? $impliedSpeed;

        return $speed < $threshold;
    }

    private function store(
        TaxiRide $ride,
        Coordinate $position,
        ?float $speedKmh,
        ?float $accuracy,
        CarbonInterface $recordedAt,
        int $distanceDelta,
        int $elapsed,
        bool $isWaiting,
        ?string $discardReason,
    ): TaxiMeterSample {
        return $ride->meterSamples()->create([
            'lat' => $position->lat,
            'lng' => $position->lng,
            'speed_kmh' => $speedKmh,
            'accuracy' => $accuracy,
            'distance_delta_meters' => $distanceDelta,
            'elapsed_seconds' => $elapsed,
            'is_waiting' => $isWaiting,
            'is_discarded' => $discardReason !== null,
            'discard_reason' => $discardReason,
            'recorded_at' => $recordedAt,
        ]);
    }
}
