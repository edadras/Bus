<?php

namespace App\Domain\Network\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Network\Enums\NetworkProvenance;
use App\Support\Concerns\BelongsToCity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatch extends Model
{
    use BelongsToCity;

    protected $fillable = [
        'city_id', 'user_id', 'entity', 'format', 'source_name', 'provenance',
        'status', 'total_rows', 'created_rows', 'updated_rows', 'skipped_rows',
        'errors', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'completed_at' => 'datetime',
            'provenance' => NetworkProvenance::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
