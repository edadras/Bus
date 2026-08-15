<?php

namespace App\Http\Requests\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'min:10', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'client' => ['nullable', Rule::in(['passenger', 'driver', 'merchant', 'admin'])],
            'device_name' => ['nullable', 'string', 'max:60'],
        ];
    }
}
