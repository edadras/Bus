<?php

namespace App\Http\Requests\V1\Driver;

use Illuminate\Foundation\Http\FormRequest;

class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->driver !== null;
    }

    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'heading' => ['nullable', 'numeric', 'min:0', 'max:360'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'altitude' => ['nullable', 'numeric', 'between:-500,9000'],
            // The device's own timestamp, so buffered pings keep their order.
            'recorded_at' => ['nullable', 'date'],
        ];
    }
}
