<?php

namespace App\Domain\SchoolTransport\Models;

use App\Domain\Identity\Models\User;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use App\Support\Geo\Coordinate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A child, as far as the transport system needs to know one.
 *
 * The medical note and the emergency contact are held here rather than in a
 * file somebody would have to go and find, because the moment they matter is
 * the moment a driver has thirty seconds and one hand free.
 */
class SchoolStudent extends Model
{
    use BelongsToCity;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'guardian_user_id', 'city_id', 'school_id', 'first_name', 'last_name',
        'national_code', 'birth_date', 'grade', 'classroom', 'gender',
        'pickup_address', 'pickup_lat', 'pickup_lng',
        'medical_notes', 'emergency_contact_name', 'emergency_contact_phone',
        'photo_path', 'is_active',
    ];

    // A child's national code has no business travelling to a client that only
    // needs to know who is on the van.
    protected $hidden = ['national_code'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(SchoolServiceContract::class);
    }

    public function tripRows(): HasMany
    {
        return $this->hasMany(SchoolTripStudent::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function pickupPoint(): ?Coordinate
    {
        return $this->pickup_lat === null || $this->pickup_lng === null
            ? null
            : new Coordinate((float) $this->pickup_lat, (float) $this->pickup_lng);
    }
}
