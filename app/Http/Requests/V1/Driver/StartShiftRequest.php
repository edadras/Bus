<?php

namespace App\Http\Requests\V1\Driver;

use Illuminate\Foundation\Http\FormRequest;

class StartShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->driver !== null;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:10', 'max:200'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
