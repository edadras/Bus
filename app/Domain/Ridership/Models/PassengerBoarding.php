<?php

namespace App\Domain\Ridership\Models;

use App\Domain\Fleet\Models\BusQrCode;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusStop;
use App\Domain\Operations\Models\Trip;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Forensic record of the scan that started a ride. */
class PassengerBoarding extends Model
{
    protected $fillable = [
        'passenger_trip_id', 'trip_id', 'user_id', 'bus_stop_id', 'bus_qr_code_id',
        'token_nonce', 'lat', 'lng', 'distance_to_bus_meters',
        'device_fingerprint', 'client_ip', 'boarded_at',
    ];

    protected $hidden = ['client_ip', 'device_fingerprint'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'distance_to_bus_meters' => 'float',
            'boarded_at' => 'datetime',
        ];
    }

    public function passengerTrip(): BelongsTo
    {
        return $this->belongsTo(PassengerTrip::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'bus_stop_id');
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(BusQrCode::class, 'bus_qr_code_id');
    }
}
