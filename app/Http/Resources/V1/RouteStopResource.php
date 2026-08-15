<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteStopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'sequence' => $this->sequence,
            'distance_from_start' => $this->distance_from_start,
            'is_timepoint' => $this->is_timepoint,
            'allows_boarding' => $this->allows_boarding,
            'allows_alighting' => $this->allows_alighting,
            'stop' => new BusStopResource($this->whenLoaded('stop')),
        ];
    }
}
