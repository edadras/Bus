<?php

namespace App\Domain\Support\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplaintMessage extends Model
{
    public const AUTHOR_PASSENGER = 'passenger';
    public const AUTHOR_AGENT = 'agent';
    public const AUTHOR_SYSTEM = 'system';

    protected $fillable = [
        'complaint_id', 'user_id', 'author_type', 'body', 'is_internal', 'read_at',
    ];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean', 'read_at' => 'datetime'];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ComplaintAttachment::class);
    }
}
