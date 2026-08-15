<?php

namespace App\Domain\Identity\Models;

use App\Domain\Network\Models\City;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Lightweight RBAC. Permissions are resolved once per request and memoised on
 * the model; the admin UI can grant a role globally (city_id null) or scoped
 * to one city, which is what makes the panel safe to hand to a city operator.
 */
trait HasRoles
{
    private ?Collection $resolvedPermissions = null;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('city_id')
            ->withTimestamps();
    }

    public function permissions(): Collection
    {
        return $this->resolvedPermissions ??= $this->roles
            ->loadMissing('permissions')
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique()
            ->values();
    }

    public function hasRole(string ...$names): bool
    {
        return $this->roles->whereIn('name', $names)->isNotEmpty();
    }

    /** Supports exact names and trailing wildcards, e.g. "fleet.buses.*". */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $granted = $this->permissions();

        if ($granted->contains($permission)) {
            return true;
        }

        return $granted->contains(function (string $name) use ($permission): bool {
            return str_ends_with($name, '*')
                && str_starts_with($permission, rtrim($name, '*'));
        });
    }

    public function hasAnyPermission(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->name === Role::SUPER_ADMIN);
    }

    /** True when the user may act on records belonging to the given city. */
    public function canAccessCity(City|int|null $city): bool
    {
        if ($city === null || $this->isSuperAdmin()) {
            return true;
        }

        $cityId = $city instanceof City ? $city->id : $city;

        return $this->roles->contains(
            fn (Role $role) => $role->pivot->city_id === null || $role->pivot->city_id === $cityId
        );
    }

    /** @return array<int, int> city ids this user is scoped to; empty = all */
    public function scopedCityIds(): array
    {
        if ($this->isSuperAdmin()) {
            return [];
        }

        $ids = $this->roles->pluck('pivot.city_id')->filter()->unique()->values()->all();

        // A single global role grants every city, so scoping must be dropped.
        return $this->roles->contains(fn (Role $role) => $role->pivot->city_id === null) ? [] : $ids;
    }

    public function assignRole(Role|string $role, ?int $cityId = null): void
    {
        $role = $role instanceof Role ? $role : Role::where('name', $role)->firstOrFail();

        $this->roles()->syncWithoutDetaching([$role->id => ['city_id' => $cityId]]);
        $this->unsetRelation('roles');
        $this->resolvedPermissions = null;
    }

    public function removeRole(Role|string $role): void
    {
        $role = $role instanceof Role ? $role : Role::where('name', $role)->firstOrFail();

        $this->roles()->detach($role->id);
        $this->unsetRelation('roles');
        $this->resolvedPermissions = null;
    }

    public function scopeWithRole(Builder $query, string $role): Builder
    {
        return $query->whereHas('roles', fn (Builder $q) => $q->where('name', $role));
    }
}
