<?php

namespace App\Http\Requests\V1\Notifications;

use Illuminate\Foundation\Http\FormRequest;

class RegisterPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The push service URL the browser handed us. It is bounded here
            // because the column is 512 chars and an oversized endpoint would
            // otherwise be silently truncated into an undeliverable one.
            'endpoint' => ['required', 'string', 'max:512', 'url', 'starts_with:https://'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:32'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
