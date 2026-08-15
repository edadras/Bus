<?php

namespace App\Support\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Public-facing identifier. Sequential ids stay internal so that resource
 * counts and creation rates are not inferable from an API response.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function resolveRouteBinding($value, $field = null)
    {
        // Accept either the uuid (public) or the numeric id (internal tooling).
        if ($field === null && is_numeric($value)) {
            return $this->where('id', $value)->first();
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
