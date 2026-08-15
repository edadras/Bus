<?php

namespace App\Domain\Support\Models;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusStop;
use App\Domain\Operations\Models\Trip;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Domain\Support\Enums\ComplaintCategory;
use App\Domain\Support\Enums\ComplaintPriority;
use App\Domain\Support\Enums\ComplaintStatus;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Concerns\BelongsToCity;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Complaint extends Model
{
    use BelongsToCity;
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'city_id', 'category', 'priority', 'status', 'subject', 'body',
        'trip_id', 'passenger_trip_id', 'bus_id', 'driver_id', 'bus_line_id',
        'bus_stop_id', 'wallet_transaction_id', 'lat', 'lng', 'occurred_at',
        'assigned_to', 'assigned_at', 'first_response_at', 'resolved_at', 'closed_at',
        'resolution_note', 'satisfaction_rating',
    ];

    protected function casts(): array
    {
        return [
            'category' => ComplaintCategory::class,
            'priority' => ComplaintPriority::class,
            'status' => ComplaintStatus::class,
            'lat' => 'float',
            'lng' => 'float',
            'occurred_at' => 'datetime',
            'assigned_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'satisfaction_rating' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $complaint): void {
            $complaint->reference ??= 'CMP-'.now()->format('ymd').'-'.strtoupper(Str::random(6));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function passengerTrip(): BelongsTo
    {
        return $this->belongsTo(PassengerTrip::class);
    }

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BusLine::class, 'bus_line_id');
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'bus_stop_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ComplaintMessage::class)->orderBy('created_at');
    }

    /** Messages the reporting passenger is allowed to see. */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('is_internal', false);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            ComplaintStatus::New->value,
            ComplaintStatus::Reviewing->value,
            ComplaintStatus::InProgress->value,
        ]);
    }

    public function firstResponseMinutes(): ?int
    {
        return $this->first_response_at === null
            ? null
            : (int) $this->created_at->diffInMinutes($this->first_response_at);
    }

    public function resolutionMinutes(): ?int
    {
        return $this->resolved_at === null
            ? null
            : (int) $this->created_at->diffInMinutes($this->resolved_at);
    }
}
