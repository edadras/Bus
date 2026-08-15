<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'avatar_url' => $this->avatar_path === null ? null : \Illuminate\Support\Facades\Storage::url($this->avatar_path),
            'locale' => $this->locale,
            'status' => $this->status->value,
            'city' => $this->whenLoaded('city', fn () => [
                'slug' => $this->city->slug,
                'name' => $this->city->name,
            ]),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'is_driver' => $this->whenLoaded('driver', fn () => $this->driver !== null),
            'preferences' => $this->preferences ?? [],
        ];
    }
}
