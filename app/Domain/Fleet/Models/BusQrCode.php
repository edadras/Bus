<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The printed code inside a bus. `public_id` is what is encoded on the sticker;
 * `secret` is the HMAC key that the sticker's companion display (or the driver
 * app) uses to derive a short lived token. The secret never leaves the server
 * in plaintext except to the authenticated driver operating that bus.
 */
class BusQrCode extends Model
{
    protected $fillable = [
        'bus_id', 'public_id', 'secret', 'version', 'is_active',
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

    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
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
