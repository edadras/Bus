<?php

namespace App\Domain\Identity\Models;

use App\Support\Concerns\BelongsToCity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use BelongsToCity;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'city_id', 'action', 'auditable_type', 'auditable_id',
        'before', 'after', 'context', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable()
    {
        return $this->morphTo();
    }

    public function scopeAction(Builder $query, string $prefix): Builder
    {
        return $query->where('action', 'like', $prefix.'%');
    }
}
