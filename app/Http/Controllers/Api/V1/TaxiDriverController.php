<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Taxi\Enums\TaxiRideStatus;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiLine;
use App\Domain\Taxi\Models\TaxiRide;
use App\Domain\Taxi\Models\TaxiSettlement;
use App\Domain\Taxi\Models\TaxiShift;
use App\Domain\Taxi\Services\TaxiLocationService;
use App\Domain\Taxi\Services\TaxiQrService;
use App\Domain\Taxi\Services\TaxiRideService;
use App\Domain\Taxi\Services\TaxiSettlementService;
use App\Domain\Taxi\Services\TaxiShiftService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TaxiLineResource;
use App\Http\Resources\V1\TaxiResource;
use App\Http\Resources\V1\TaxiRideResource;
use App\Http\Resources\V1\TaxiSettlementResource;
use App\Http\Resources\V1\TaxiShiftResource;
use App\Support\Api\ApiResponse;
use App\Support\Exceptions\DomainException;
use App\Support\Geo\Coordinate;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The taxi driver app's API.
 *
 * Like the bus driver's, every action re-derives the driver from the token
 * rather than trusting an id, and the question of whether they may drive this
 * car today is answered in the domain.
 */
class TaxiDriverController extends Controller
{
    public function __construct(
        private readonly TaxiShiftService $shifts,
        private readonly TaxiQrService $qr,
        private readonly TaxiLocationService $locations,
        private readonly TaxiSettlementService $settlements,
    ) {}

    /** Everything the app needs on launch. */
    public function state(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $shift = $this->shifts->openShiftFor($driver);

        $assignments = $driver->taxiAssignments()
            ->current()
            ->with(['taxi.defaultLine'])
            ->get()
            ->filter(fn ($assignment) => $assignment->taxi !== null);

        return ApiResponse::success([
            'driver' => [
                'uuid' => $driver->uuid,
                'name' => $driver->user?->name,
                'status' => $driver->status->value,
                'license_expires_at' => $driver->license_expires_at?->toDateString(),
                // The reason a shift would be refused, before they try.
                'blocker' => $driver->personalBlocker(),
            ],
            'assigned_taxis' => $assignments->map(fn ($assignment) => [
                'taxi' => (new TaxiResource($assignment->taxi->load('defaultLine')))->resolve(),
            ])->values(),
            'shift' => $shift === null
                ? null
                : (new TaxiShiftResource($shift->load(['taxi', 'line'])))->resolve(),
            'qr' => $shift === null ? null : $this->qrPayload($shift->taxi),
            'wallet' => [
                'balance' => $driver->user === null ? 0 : app(WalletService::class)
                    ->forUser($driver->user)->balance,
            ],
            'pending_settlement' => $this->settlements->pendingBalance($driver),
        ]);
    }

    /** Available shared-taxi lines, for the mode picker. */
    public function lines(Request $request): JsonResponse
    {
        $lines = TaxiLine::forCity($this->city())->active()->orderBy('code')->get();

        return ApiResponse::success(TaxiLineResource::collection($lines));
    }

    public function startShift(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $validated = $request->validate([
            'taxi_uuid' => ['required', 'uuid'],
            'service_type' => ['required', Rule::in(TaxiServiceType::values())],
            'taxi_line_id' => ['nullable', 'integer', 'exists:taxi_lines,id'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $taxi = Taxi::forCity($this->city())->where('uuid', $validated['taxi_uuid'])->firstOrFail();
        $line = isset($validated['taxi_line_id'])
            ? TaxiLine::forCity($this->city())->findOrFail($validated['taxi_line_id'])
            : null;

        $shift = $this->shifts->open(
            driver: $driver,
            taxi: $taxi,
            mode: TaxiServiceType::from($validated['service_type']),
            line: $line,
            at: $this->positionFrom($validated),
        );

        return ApiResponse::success([
            'shift' => (new TaxiShiftResource($shift->load(['taxi', 'line'])))->resolve(),
            'qr' => $this->qrPayload($taxi),
        ], status: 201);
    }

    public function endShift(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $shift = $this->openShift($request);
        $closed = $this->shifts->close($shift, $this->positionFrom($validated));

        return ApiResponse::success((new TaxiShiftResource($closed->load(['taxi', 'line'])))->resolve());
    }

    /** Change what the car is offering. */
    public function switchMode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => ['required', Rule::in(TaxiServiceType::values())],
            'taxi_line_id' => ['nullable', 'integer', 'exists:taxi_lines,id'],
        ]);

        $line = isset($validated['taxi_line_id'])
            ? TaxiLine::forCity($this->city())->findOrFail($validated['taxi_line_id'])
            : null;

        $shift = $this->shifts->switchMode(
            $this->openShift($request),
            TaxiServiceType::from($validated['service_type']),
            $line,
        );

        return ApiResponse::success((new TaxiShiftResource($shift->load(['taxi', 'line'])))->resolve());
    }

    /** Name the price for the hire in front of the driver. */
    public function setCharterAmount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:'.(int) config('taxi.charter.max_amount')],
        ]);

        $shift = $this->shifts->setCharterAmount($this->openShift($request), (int) $validated['amount']);

        return ApiResponse::success([
            'pending_charter_amount' => $shift->pendingCharterAmount(),
            'formatted_amount' => Money::format((int) $validated['amount']),
            'expires_in' => (int) config('taxi.charter.quote_ttl_seconds'),
        ]);
    }

    public function clearCharterAmount(Request $request): JsonResponse
    {
        $this->shifts->clearCharterAmount($this->openShift($request));

        return ApiResponse::success(['pending_charter_amount' => null]);
    }

    /** The rotating code shown to passengers. */
    public function qr(Request $request): JsonResponse
    {
        $shift = $this->openShift($request);

        return ApiResponse::success($this->qrPayload($shift->taxi));
    }

    /**
     * Where the car is.
     *
     * One report does three things: moves the dot on the live map, records the
     * car's last position, and — when a meter is running — advances the fare.
     */
    public function location(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'speed' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $shift = $this->openShift($request);

        $result = $this->locations->report(
            shift: $shift,
            position: new Coordinate((float) $validated['lat'], (float) $validated['lng']),
            speedKmh: isset($validated['speed']) ? (float) $validated['speed'] : null,
            accuracy: isset($validated['accuracy']) ? (float) $validated['accuracy'] : null,
            recordedAt: isset($validated['recorded_at']) ? Carbon::parse($validated['recorded_at']) : null,
        );

        return ApiResponse::success([
            'accepted' => $result['accepted'],
            'next_report_in' => $result['next_report_in'],
            'ride' => $result['ride'] === null
                ? null
                : (new TaxiRideResource($result['ride']))->resolve(),
            'current_fare' => $result['fare'],
        ]);
    }

    /** Who is aboard, and what has been taken so far. */
    public function rides(Request $request): JsonResponse
    {
        $shift = $this->openShift($request);

        $rides = $shift->rides()
            ->with(['line'])
            ->orderByDesc('started_at')
            ->limit(50)
            ->get();

        return ApiResponse::success([
            'shift' => (new TaxiShiftResource($shift->load(['taxi', 'line'])))->resolve(),
            'onboard' => TaxiRideResource::collection(
                $rides->where('status', TaxiRideStatus::Active)->values()
            )->resolve(),
            'recent' => TaxiRideResource::collection($rides)->resolve(),
        ]);
    }

    /**
     * End a ride from the driver's side.
     *
     * A metered ride normally ends when the passenger says so, but the driver
     * is the one who knows the car has stopped, and a passenger whose phone has
     * died must not leave a meter running.
     */
    public function endRide(Request $request, TaxiRide $taxiRide): JsonResponse
    {
        $driver = $this->driver($request);

        abort_unless($taxiRide->driver_id === $driver->id, 404);

        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $ride = app(TaxiRideService::class)
            ->end($taxiRide, 'driver', $this->positionFrom($validated));

        return ApiResponse::success((new TaxiRideResource($ride->load(['taxi', 'line'])))->resolve());
    }

    /** Earnings over a window, which is what a driver actually asks for. */
    public function earnings(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $from = $request->date('from') ?? now()->subDays(30);
        $to = $request->date('to') ?? now();

        $rides = TaxiRide::query()
            ->where('driver_id', $driver->id)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        $paid = $rides->where('fare_amount', '>', 0);

        $byDay = $paid
            ->groupBy(fn (TaxiRide $ride) => $ride->created_at->toDateString())
            ->map(fn ($group, $date) => [
                'date' => $date,
                'ride_count' => $group->count(),
                'gross' => (int) $group->sum('fare_amount'),
                'net' => (int) $group->sum(fn (TaxiRide $r) => $r->fare_amount - $r->commission_amount),
            ])
            ->values();

        $byMode = $paid
            ->groupBy(fn (TaxiRide $ride) => $ride->service_type->value)
            ->map(fn ($group, $mode) => [
                'service_type' => $mode,
                'label' => TaxiServiceType::from($mode)->label(),
                'ride_count' => $group->count(),
                'gross' => (int) $group->sum('fare_amount'),
            ])
            ->values();

        return ApiResponse::success([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'ride_count' => $paid->count(),
            'gross' => (int) $paid->sum('fare_amount'),
            'commission' => (int) $paid->sum('commission_amount'),
            'net' => (int) $paid->sum(fn (TaxiRide $r) => $r->fare_amount - $r->commission_amount),
            'distance_meters' => (int) $paid->sum('distance_meters'),
            // Shown separately rather than folded into the total: a fare the
            // passenger never paid is not earnings, and hiding it would make
            // the driver's own figures disagree with their payout.
            'unpaid_count' => $rides->where('status', TaxiRideStatus::Unpaid)->count(),
            'unpaid_amount' => (int) $rides->where('status', TaxiRideStatus::Unpaid)->sum('outstanding_amount'),
            'by_day' => $byDay,
            'by_mode' => $byMode,
        ]);
    }

    public function settlements(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $settlements = TaxiSettlement::where('driver_id', $driver->id)
            ->orderByDesc('created_at')
            ->paginate(min(50, $request->integer('per_page', 20)));

        return ApiResponse::paginated(
            TaxiSettlementResource::collection($settlements),
            ['pending' => $this->settlements->pendingBalance($driver)],
        );
    }

    public function requestSettlement(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $settlement = $this->settlements->request(
            driver: $driver,
            requestedBy: $request->user(),
            from: isset($validated['from']) ? $request->date('from') : null,
            to: isset($validated['to']) ? $request->date('to') : null,
        );

        return ApiResponse::success(
            (new TaxiSettlementResource($settlement))->resolve(),
            status: 201,
        );
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function driver(Request $request): Driver
    {
        $driver = $request->user()->driver;

        if ($driver === null) {
            throw DomainException::make('not_a_driver', 403);
        }

        return $driver;
    }

    private function openShift(Request $request): TaxiShift
    {
        $shift = $this->shifts->openShiftFor($this->driver($request));

        if ($shift === null) {
            throw DomainException::make('no_open_shift', 409);
        }

        return $shift;
    }

    private function qrPayload(Taxi $taxi): ?array
    {
        $qr = $taxi->activeQrCode;

        return $qr === null ? null : $this->qr->currentToken($qr);
    }

    private function positionFrom(array $validated): ?Coordinate
    {
        return isset($validated['lat'], $validated['lng'])
            ? new Coordinate((float) $validated['lat'], (float) $validated['lng'])
            : null;
    }
}
