<?php

namespace App\Domain\Merchant\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantStaff extends Model
{
    protected $table = 'merchant_staff';

    protected $fillable = [
        'merchant_id', 'user_id', 'role', 'can_refund', 'can_view_reports', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'can_refund' => 'boolean',
            'can_view_reports' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }
}
