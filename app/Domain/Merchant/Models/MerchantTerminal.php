<?php

namespace App\Domain\Merchant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A till or kiosk. Each has its own HMAC secret so one can be revoked alone. */
class MerchantTerminal extends Model
{
    protected $fillable = [
        'merchant_id', 'name', 'public_id', 'secret', 'location_label', 'is_active', 'last_used_at',
    ];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MerchantTransaction::class);
    }
}
