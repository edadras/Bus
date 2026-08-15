<?php

namespace App\Domain\Analytics\Models;

use App\Support\Concerns\BelongsToCity;
use Illuminate\Database\Eloquent\Model;

/** Pre-aggregated dashboard facts; rebuilt nightly and on demand. */
class DailyMetric extends Model
{
    use BelongsToCity;

    protected $fillable = [
        'city_id', 'date', 'trips_count', 'active_buses', 'active_drivers',
        'boardings_count', 'unique_passengers', 'new_users',
        'fare_revenue', 'topup_amount', 'merchant_volume', 'refund_amount',
        'transactions_count', 'complaints_opened', 'complaints_resolved',
        'avg_passengers_per_trip', 'avg_speed_kmh', 'avg_eta_error_seconds',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'avg_passengers_per_trip' => 'float',
            'avg_speed_kmh' => 'float',
            'avg_eta_error_seconds' => 'float',
        ];
    }
}
