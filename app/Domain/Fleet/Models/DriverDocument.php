<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Fleet\Enums\DriverDocumentType;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDocument extends Model
{
    protected $fillable = [
        'driver_id', 'type', 'file_path', 'original_name', 'mime_type', 'size_bytes',
        'issued_at', 'expires_at', 'is_verified', 'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
            'verified_at' => 'datetime',
            'is_verified' => 'boolean',
            'type' => DriverDocumentType::class,
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
