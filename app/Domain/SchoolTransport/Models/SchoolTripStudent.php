<?php

namespace App\Domain\SchoolTransport\Models;

use App\Domain\Identity\Models\User;
use App\Domain\SchoolTransport\Enums\SchoolAttendanceStatus;
use App\Support\Concerns\HasUuid;
use App\Support\Geo\Coordinate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One child on one run: where they are collected, and what happened.
 *
 * `recorded_by` is not bookkeeping. "The child was marked absent" is a claim
 * somebody made, and the first question a parent asks is who.
 */
class SchoolTripStudent extends Model
{
    use HasUuid;

    protected $table = 'school_trip_students';

    protected $fillable = [
        'school_trip_id', 'school_student_id', 'school_service_contract_id',
        'sequence', 'status', 'pickup_address', 'pickup_lat', 'pickup_lng',
        'picked_up_at', 'picked_up_lat', 'picked_up_lng',
        'dropped_off_at', 'dropped_off_lat', 'dropped_off_lng',
        'recorded_by', 'note',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'picked_up_at' => 'datetime',
            'picked_up_lat' => 'float',
            'picked_up_lng' => 'float',
            'dropped_off_at' => 'datetime',
            'dropped_off_lat' => 'float',
            'dropped_off_lng' => 'float',
            'status' => SchoolAttendanceStatus::class,
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(SchoolTrip::class, 'school_trip_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(SchoolStudent::class, 'school_student_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(SchoolServiceContract::class, 'school_service_contract_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function pickupPoint(): ?Coordinate
    {
        return $this->pickup_lat === null || $this->pickup_lng === null
            ? null
            : new Coordinate((float) $this->pickup_lat, (float) $this->pickup_lng);
    }
}
