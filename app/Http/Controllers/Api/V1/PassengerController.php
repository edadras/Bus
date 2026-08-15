<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ridership\Models\PassengerTrip;
use App\Domain\Ridership\Services\AlightingService;
use App\Domain\Ridership\Services\BoardingService;
use App\Domain\Wallet\Services\FareEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Ridership\BoardRequest;
use App\Http\Requests\V1\Ridership\PassengerPingRequest;
use App\Http\Resources\V1\PassengerTripResource;
use App\Http\Resources\V1\TripResource;
use App\Support\Api\ApiResponse;
use App\Support\Geo\Coordinate;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Boarding, riding and ride history — the passenger's side of a journey. */
class PassengerController extends Controller
{
    public function __construct(
        private readonly BoardingService $boarding,
        private readonly AlightingService $alighting,
        private readonly FareEngine $fares,
    ) {}

    /** Scan the QR inside the bus: verifies, charges and seats the passenger. */
    public function scan(BoardRequest $request): JsonResponse
    {
        $result = $this->boarding->board(
            user: $request->user(),
            rawToken: $request->string('token')->toString(),
            context: [
                'lat' => $request->input('lat'),
                'lng' => $request->input('lng'),
                'device' => $request->string('device_id')->toString() ?: null,
                'ip' => $request->ip(),
            ],
        );

        $trip = $result['trip']->load(['bus', 'line', 'destinationStop', 'nextStop']);

        return ApiResponse::success([
            'passenger_trip' => (new PassengerTripResource($result['passenger_trip']))->resolve(),
            'trip' => (new TripResource($trip))->resolve(),
            'fare' => [
                'amount' => $result['fare'],
                'formatted' => Money::format($result['fare']),
                'breakdown' => $result['fare_breakdown'],
            ],
            'balance' => [
                'amount' => $result['balance'],
                'formatted' => Money::format($result['balance']),
            ],
        ], status: 201);
    }

    /** The ride currently in progress, if any. */
    public function activeRide(Request $request): JsonResponse
    {
        $ride = PassengerTrip::where('user_id', $request->user()->id)
            ->open()
            ->with(['trip.bus', 'trip.line', 'trip.nextStop', 'trip.destinationStop', 'line', 'boardingStop'])
            ->latest('boarded_at')
            ->first();

        if ($ride === null) {
            return ApiResponse::success(null);
        }

        return ApiResponse::success([
            'passenger_trip' => (new PassengerTripResource($ride))->resolve(),
            'trip' => $ride->trip === null ? null : (new TripResource($ride->trip))->resolve(),
        ]);
    }

    /**
     * Opt-in location sample while riding, used only to detect that the
     * passenger has got off. Samples are pruned shortly after the ride ends.
     */
    public function ping(PassengerPingRequest $request, PassengerTrip $passengerTrip): JsonResponse
    {
        abort_unless($passengerTrip->user_id === $request->user()->id, 404);

        $result = $this->alighting->observe(
            $passengerTrip,
            new Coordinate($request->float('lat'), $request->float('lng')),
            $request->has('accuracy') ? $request->float('accuracy') : null,
        );

        return ApiResponse::success($result);
    }

    /** Passenger declares they have left the bus; always taken at face value. */
    public function endRide(Request $request, PassengerTrip $passengerTrip): JsonResponse
    {
        abort_unless($passengerTrip->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $position = isset($validated['lat'], $validated['lng'])
            ? new Coordinate((float) $validated['lat'], (float) $validated['lng'])
            : null;

        $ride = $this->alighting->closeManually($passengerTrip, $position);

        return ApiResponse::success(
            (new PassengerTripResource($ride->load(['line', 'bus', 'boardingStop', 'alightingStop'])))->resolve(),
        );
    }

    public function history(Request $request): JsonResponse
    {
        $rides = PassengerTrip::where('user_id', $request->user()->id)
            ->with(['line', 'bus:id,bus_number', 'boardingStop:id,name', 'alightingStop:id,name'])
            ->orderByDesc('boarded_at')
            ->paginate(min(50, $request->integer('per_page', 20)));

        return ApiResponse::paginated(PassengerTripResource::collection($rides));
    }

    /** Quote the fare before boarding, so the price is never a surprise. */
    public function fareQuote(Request $request): JsonResponse
    {
        $validated = $request->validate(['line_id' => ['required', 'integer', 'exists:bus_lines,id']]);

        $line = \App\Domain\Network\Models\BusLine::findOrFail($validated['line_id']);

        abort_unless($line->city_id === $this->city()->id, 404);

        return ApiResponse::success($this->fares->quoteForLine($line, $request->user())->toArray());
    }
}
