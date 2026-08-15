<?php

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Enums\TripEventType;
use App\Domain\Operations\Models\Trip;
use App\Domain\Operations\Models\TripEvent;

/** Single funnel for the trip audit trail, so every event looks the same. */
class TripEventRecorder
{
    public function record(Trip $trip, TripEventType $type, array $payload = []): TripEvent
    {
        return TripEvent::create([
            'trip_id' => $trip->id,
            'type' => $type,
            'bus_stop_id' => $payload['stop_id'] ?? null,
            'lat' => $payload['lat'] ?? $trip->current_lat,
            'lng' => $payload['lng'] ?? $trip->current_lng,
            'payload' => $payload ?: null,
            'occurred_at' => now(),
        ]);
    }
}
