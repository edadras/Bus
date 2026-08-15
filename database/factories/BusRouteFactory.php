<?php

namespace Database\Factories;

use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Enums\RouteDirection;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusRoute> */
class BusRouteFactory extends Factory
{
    protected $model = BusRoute::class;

    public function definition(): array
    {
        return [
            'bus_line_id' => BusLine::factory(),
            'name' => 'مسیر رفت',
            'direction' => RouteDirection::Outbound,
            'is_active' => true,
            'is_default' => true,
            'provenance' => NetworkProvenance::Sample,
        ];
    }
}
