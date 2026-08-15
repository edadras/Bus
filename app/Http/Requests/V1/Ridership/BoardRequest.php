<?php

namespace App\Http\Requests\V1\Ridership;

use Illuminate\Foundation\Http\FormRequest;

class BoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:10', 'max:200'],
            // Position is optional: indoor GPS is unreliable and refusing a
            // fare over it would be worse than the residual risk. When present
            // it is checked against the bus's own position.
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'device_id' => ['nullable', 'string', 'max:64'],
        ];
    }
}
