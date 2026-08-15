<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusStop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Tell me when line X is N minutes from stop Y." */
class StopArrivalSubscription extends Model
{
    protected $fillable = [
        'user_id', 'bus_stop_id', 'bus_line_id', 'notify_minutes_before',
        'is_active', 'last_notified_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'notify_minutes_before' => 'integer',
            'is_active' => 'boolean',
            'last_notified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stop(): BelongsTo
    {
        return $this->belongsTo(BusStop::class, 'bus_stop_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BusLine::class, 'bus_line_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Debounce so one approaching bus cannot fire a burst of notifications. */
    public function canNotify(): bool
    {
        return $this->last_notified_at === null || $this->last_notified_at->lt(now()->subMinutes(10));
    }
}
