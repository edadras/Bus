<?php

namespace App\Domain\Operations\Models;

use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rolling per-segment travel time statistics, one row per
 * (route, from stop, to stop, day type, hour). Updated with Welford's online
 * algorithm so a new observation costs O(1) and never needs the raw history.
 */
class SegmentTravelStat extends Model
{
    protected $fillable = [
        'route_id', 'from_stop_id', 'to_stop_id', 'day_type', 'hour_bucket',
        'sample_count', 'mean_seconds', 'm2', 'p50_seconds', 'p85_seconds',
        'traffic_factor', 'last_sample_at',
    ];

    protected function casts(): array
    {
        return [
            'sample_count' => 'integer',
            'hour_bucket' => 'integer',
            'mean_seconds' => 'float',
            'm2' => 'float',
            'p50_seconds' => 'integer',
            'p85_seconds' => 'integer',
            'traffic_factor' => 'float',
            'last_sample_at' => 'datetime',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(BusRoute::class, 'route_id');
    }

    public function fromStop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'from_stop_id');
    }

    public function toStop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'to_stop_id');
    }

    public function variance(): float
    {
        return $this->sample_count > 1 ? $this->m2 / ($this->sample_count - 1) : 0.0;
    }

    public function standardDeviation(): float
    {
        return sqrt($this->variance());
    }

    public function isTrusted(): bool
    {
        return $this->sample_count >= (int) config('transit.eta.min_historical_samples');
    }

    /**
     * Fold a new observation in, in constant time.
     * Welford: mean_n = mean_{n-1} + (x - mean_{n-1}) / n
     *          M2_n   = M2_{n-1} + (x - mean_{n-1})(x - mean_n)
     */
    public function addSample(float $seconds): void
    {
        $count = $this->sample_count + 1;
        $delta = $seconds - $this->mean_seconds;
        $mean = $this->mean_seconds + $delta / $count;

        $this->sample_count = $count;
        $this->mean_seconds = $mean;
        $this->m2 = $this->m2 + $delta * ($seconds - $mean);
        $this->last_sample_at = now();
    }
}
