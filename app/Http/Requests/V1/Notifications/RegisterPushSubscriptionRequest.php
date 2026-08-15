<?php

namespace App\Http\Requests\V1\Notifications;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Registration for both kinds of device.
 *
 * A browser produces an endpoint URL plus two keys; a phone produces a single
 * FCM registration token. Both land in the same row — `endpoint` holds either
 * — so the rules differ only in what each platform must supply.
 */
class RegisterPushSubscriptionRequest extends FormRequest
{
    private const NATIVE = ['android', 'ios'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Bounded here because the column is 512 chars and an oversized
            // value would otherwise be silently truncated into an
            // undeliverable one.
            'endpoint' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'in:web,android,ios'],
            'keys' => ['nullable', 'array'],
            'keys.p256dh' => ['nullable', 'string', 'max:255'],
            'keys.auth' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->isNative()) {
                return;
            }

            // A browser subscription without its keys cannot be encrypted to,
            // and a plain-http endpoint is not a push service.
            if (! filled($this->input('keys.p256dh')) || ! filled($this->input('keys.auth'))) {
                $validator->errors()->add('keys', __('validation.required', [
                    'attribute' => __('validation.attributes.push_keys'),
                ]));
            }

            if (! str_starts_with((string) $this->input('endpoint'), 'https://')) {
                $validator->errors()->add('endpoint', __('validation.url', [
                    'attribute' => __('validation.attributes.push_endpoint'),
                ]));
            }
        });
    }

    public function isNative(): bool
    {
        return in_array((string) $this->input('platform'), self::NATIVE, true);
    }
}
