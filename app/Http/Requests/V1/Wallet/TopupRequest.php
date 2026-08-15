<?php

namespace App\Http\Requests\V1\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class TopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'amount' => [
                'required',
                'integer',
                'min:'.(int) config('wallet.limits.min_topup'),
                'max:'.(int) config('wallet.limits.max_topup'),
            ],
            'gateway' => ['nullable', 'string', 'max:32'],
            'return_url' => ['nullable', 'url', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => __('validation.custom.amount.min_topup'),
            'amount.max' => __('validation.custom.amount.max_topup'),
        ];
    }
}
