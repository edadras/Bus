<?php

namespace App\Http\Requests\V1\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class ChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:10', 'max:200'],
            'amount' => ['required', 'integer', 'min:1000', 'max:'.(int) config('wallet.limits.max_topup')],
            'description' => ['nullable', 'string', 'max:200'],
        ];
    }
}
