<?php

namespace App\Domain\Fleet\Models;

use App\Support\Concerns\BelongsToCity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A company or municipal department that owns buses and employs drivers. */
class Operator extends Model
{
    use BelongsToCity;
    use HasFactory;

    protected $fillable = ['city_id', 'name', 'code', 'contact_name', 'contact_phone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function buses(): HasMany
    {
        return $this->hasMany(Bus::class);
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }
}
