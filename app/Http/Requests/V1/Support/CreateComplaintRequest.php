<?php

namespace App\Http\Requests\V1\Support;

use App\Domain\Support\Enums\ComplaintCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(ComplaintCategory::values())],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:4000'],
            'trip_id' => ['nullable', 'integer', 'exists:trips,id'],
            'passenger_trip_id' => ['nullable', 'integer', 'exists:passenger_trips,id'],
            'bus_id' => ['nullable', 'integer', 'exists:buses,id'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'bus_line_id' => ['nullable', 'integer', 'exists:bus_lines,id'],
            'bus_stop_id' => ['nullable', 'integer', 'exists:bus_stops,id'],
            'wallet_transaction_id' => ['nullable', 'integer', 'exists:wallet_transactions,id'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
