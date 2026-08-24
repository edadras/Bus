<?php

namespace App\Domain\Taxi\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The code displayed in a taxi. Same shape as the bus code and for the same
 * reason: the sticker carries only a public id, and a scan is worth nothing
 * without a token signed by the secret held here.
 */
class TaxiQrCode extends Model
{
    protected $fillable = [
        'taxi_id', 'public_id', 'secret', 'version', 'is_active',
        'activated_at', 'revoked_at', 'revoked_by', 'revoke_reason',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'version' => 'integer',
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function taxi(): BelongsTo
    {
        return $this->belongsTo(Taxi::class);
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->revoked_at === null;
    }
}
