<?php

namespace App\Domain\Network\Models;

use App\Support\Concerns\BelongsToCity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    use BelongsToCity;
    use HasFactory;

    protected $fillable = ['city_id', 'code', 'name', 'boundary', 'is_active'];

    protected function casts(): array
    {
        return ['boundary' => 'array', 'is_active' => 'boolean'];
    }

    public function stops(): HasMany
    {
        return $this->hasMany(BusStop::class);
    }
}
