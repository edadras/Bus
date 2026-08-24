<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Taxi\Enums\TaxiRideStatus;
use App\Domain\Taxi\Models\TaxiLine;
use App\Domain\Taxi\Models\TaxiRide;
use App\Domain\Taxi\Services\TaxiLiveService;
use App\Domain\Taxi\Services\TaxiMeterService;
use App\Domain\Taxi\Services\TaxiRideService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TaxiLineResource;
use App\Http\Resources\V1\TaxiRideResource;
use App\Support\Api\ApiResponse;
use App\Support\Geo\Coordinate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The passenger's side of the taxi network.
 *
 * The one rule that shapes this controller: a scan is priced by the server and
 * confirmed by the passenger. `scan` shows what a ride would cost and commits
 * to nothing; `store` takes the ride, and the amount it accepts is only ever
 * compared against the server's own figure.
 */
class TaxiController extends Controller
{
    public function __construct(
        private readonly TaxiRideService $rides,
        private readonly TaxiLiveService $live,
        private readonly TaxiMeterService $meter,
    ) {}

    /**
     * Taxis near me.
     *
     * Deliberately a "near me" feed and nothing else: a position is required,
     * the radius is capped server-side, and the payload carries no plate, no
     * driver and no passenger. A city-wide feed of every taxi with its driver
     * would be a tracking service for taxi drivers, which is not what a rider
     * looking for a car needs.
     */
    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'integer', 'min:100', 'max:5000'],
            'service_type' => ['nullable', 'in:line,charter,meter'],
        ]);

        $taxis = $this->live->nearby(
            cityId: $this->city()->id,
            centre: new Coordinate((float) $validated['lat'], (float) $validated['lng']),
            radiusMeters: (int) ($validated['radius'] ?? 1500),
            mode: $validated['service_type'] ?? null,
        );

        return ApiResponse::success($taxis, [
            'count' => count($taxis),
            'radius_meters' => min(
                (int) ($validated['radius'] ?? 1500),
                (int) config('taxi.live.public_max_radius_meters'),
            ),
        ]);
    }

    /** The published shared-taxi lines and their flat fares. */
    public function lines(Request $request): JsonResponse
    {
        $lines = TaxiLine::forCity($this->city())
            ->active()
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->orderBy('code')
            ->get();

        return ApiResponse::success(TaxiLineResource::collection($lines));
    }

    /**
     * What would this ride cost?
     *
     * Consumes nothing: a passenger who reads a charter price and decides
     * against it must not have burned a code they may want five seconds later.
     */
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $result = $this->rides->quote($request->user(), $validated['token'], [
            'lat' => $validated['lat'] ?? null,
            'lng' => $validated['lng'] ?? null,
            'ip' => $request->ip(),
        ]);

        $shift = $result['shift'];

        return ApiResponse::success([
            'quote' => $result['quote']->toArray(),
            'taxi' => [
                'uuid' => $result['taxi']->uuid,
                'taxi_number' => $result['taxi']->taxi_number,
                'model' => $result['taxi']->model,
                'color' => $result['taxi']->color,
                'plate' => $result['taxi']->plate,
            ],
            'service_type' => $shift->service_type->value,
            'service_type_label' => $shift->service_type->label(),
            'line' => $shift->line === null ? null : (new TaxiLineResource($shift->line))->resolve(),
            // The passenger has to send this back to take the ride, which is
            // what makes an amount they never saw impossible to charge.
            'requires_amount_confirmation' => $shift->service_type->isPricedUpFront(),
        ]);
    }

    /** Take the ride. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'accepted_amount' => ['nullable', 'integer', 'min:0'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'device_id' => ['nullable', 'string', 'max:128'],
        ]);

        $ride = $this->rides->confirm(
            user: $request->user(),
            rawToken: $validated['token'],
            acceptedAmount: $validated['accepted_amount'] ?? null,
            context: [
                'lat' => $validated['lat'] ?? null,
                'lng' => $validated['lng'] ?? null,
                'device' => $validated['device_id'] ?? null,
                'ip' => $request->ip(),
            ],
        );

        return ApiResponse::success(
            $this->ridePayload($ride->load(['taxi', 'line', 'driver.user', 'tariff'])),
            status: 201,
        );
    }

    /** The ride in progress, with the meter reading if one is running. */
    public function active(Request $request): JsonResponse
    {
        $ride = $this->rides->activeRideFor($request->user());

        return ApiResponse::success($ride === null ? null : $this->ridePayload($ride));
    }

    public function end(Request $request, TaxiRide $taxiRide): JsonResponse
    {
        abort_unless($taxiRide->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $position = isset($validated['lat'], $validated['lng'])
            ? new Coordinate((float) $validated['lat'], (float) $validated['lng'])
            : null;

        $ride = $this->rides->end($taxiRide, 'passenger', $position);

        return ApiResponse::success($this->ridePayload($ride->load(['taxi', 'line', 'driver.user'])));
    }

    public function history(Request $request): JsonResponse
    {
        $rides = TaxiRide::with(['taxi', 'line', 'driver.user'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('started_at')
            ->paginate(min(50, $request->integer('per_page', 20)));

        return ApiResponse::paginated(TaxiRideResource::collection($rides), [
            'outstanding' => $this->rides->outstandingFor($request->user()),
        ]);
    }

    /** Pay off a metered ride the wallet could not cover at the time. */
    public function settle(Request $request, TaxiRide $taxiRide): JsonResponse
    {
        abort_unless($taxiRide->user_id === $request->user()->id, 404);

        if ($taxiRide->status !== TaxiRideStatus::Unpaid) {
            return ApiResponse::success($this->ridePayload($taxiRide));
        }

        $ride = $this->rides->settleOutstanding($taxiRide);

        return ApiResponse::success($this->ridePayload($ride->load(['taxi', 'line', 'driver.user'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function ridePayload(TaxiRide $ride): array
    {
        $payload = (new TaxiRideResource($ride))->resolve();

        // A running meter is worth more than a stored total: the passenger is
        // watching it, and a number that only appears at the destination is a
        // surprise rather than a price.
        if ($ride->isOpen() && $ride->service_type->isOpenEnded()) {
            $payload['current_fare'] = $this->meter->currentQuote($ride)?->toArray();
        }

        return $payload;
    }
}
