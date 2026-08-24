<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Services\AuditLogger;
use App\Domain\Taxi\Enums\TaxiRideStatus;
use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiAssignment;
use App\Domain\Taxi\Models\TaxiLine;
use App\Domain\Taxi\Models\TaxiRide;
use App\Domain\Taxi\Models\TaxiSettlement;
use App\Domain\Taxi\Models\TaxiTariff;
use App\Domain\Taxi\Services\TaxiLiveService;
use App\Domain\Taxi\Services\TaxiQrService;
use App\Domain\Taxi\Services\TaxiSettlementService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AssignmentResource;
use App\Http\Resources\V1\TaxiLineResource;
use App\Http\Resources\V1\TaxiResource;
use App\Http\Resources\V1\TaxiRideResource;
use App\Http\Resources\V1\TaxiSettlementResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Running the taxi fleet from the control room.
 *
 * The live feed here is the counterpart of the passenger's "near me": this one
 * is the whole city with the plate, the driver and who is aboard, because
 * dispatching needs that. It is gated on `operations.live_map`, and the split
 * between the two feeds is deliberate — a rider looking for a car gets a car,
 * not a roster of who is driving it.
 */
class TaxiAdminController extends Controller
{
    public function __construct(
        private readonly TaxiQrService $qr,
        private readonly TaxiLiveService $live,
        private readonly TaxiSettlementService $settlements,
        private readonly AuditLogger $audit,
    ) {}

    // ── fleet ───────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $taxis = Taxi::forCity($this->city())
            ->with(['currentDriver.user', 'defaultLine'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($sub) => $sub->where('taxi_number', 'like', $term)->orWhere('plate', 'like', $term));
            })
            ->orderBy('taxi_number')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paginated(TaxiResource::collection($taxis));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'taxi_number' => ['required', 'string', 'max:32',
                Rule::unique('taxis')->where(fn ($q) => $q->where('city_id', $this->city()->id))],
            'plate' => ['nullable', 'string', 'max:32'],
            'model' => ['nullable', 'string', 'max:150'],
            'color' => ['nullable', 'string', 'max:32'],
            'manufacture_year' => ['nullable', 'integer', 'min:1980', 'max:2100'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:12'],
            'has_air_conditioning' => ['boolean'],
            'is_accessible' => ['boolean'],
            'status' => ['nullable', 'string', 'max:32'],
            'allowed_modes' => ['nullable', 'array'],
            'allowed_modes.*' => [Rule::in(TaxiServiceType::values())],
            'default_taxi_line_id' => ['nullable', 'integer', 'exists:taxi_lines,id'],
            'commission_bps' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'operator_id' => ['nullable', 'integer', 'exists:operators,id'],
            'inspection_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $taxi = Taxi::create($validated + ['city_id' => $this->city()->id]);

        // Issued on creation: a taxi with no code cannot take a fare, and
        // leaving that to a second click is how cars reach the street mute.
        $this->qr->issueFor($taxi);

        $this->audit->log('taxi.created', $taxi, $request->user(), after: $validated);

        return ApiResponse::success(
            (new TaxiResource($taxi->refresh()->load('defaultLine')))->resolve(),
            status: 201,
        );
    }

    public function update(Request $request, Taxi $taxi): JsonResponse
    {
        abort_unless($taxi->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'plate' => ['nullable', 'string', 'max:32'],
            'model' => ['nullable', 'string', 'max:150'],
            'color' => ['nullable', 'string', 'max:32'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'has_air_conditioning' => ['boolean'],
            'is_accessible' => ['boolean'],
            'status' => ['sometimes', 'string', 'max:32'],
            'allowed_modes' => ['nullable', 'array'],
            'allowed_modes.*' => [Rule::in(TaxiServiceType::values())],
            'default_taxi_line_id' => ['nullable', 'integer', 'exists:taxi_lines,id'],
            'commission_bps' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'inspection_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $taxi->fill($validated);
        $this->audit->logChange('taxi.updated', $taxi, $request->user());
        $taxi->save();

        return ApiResponse::success((new TaxiResource($taxi->load('defaultLine')))->resolve());
    }

    public function qrCode(Request $request, Taxi $taxi): JsonResponse
    {
        abort_unless($taxi->city_id === $this->city()->id, 404);

        $qr = $taxi->activeQrCode ?? $this->qr->issueFor($taxi);

        return ApiResponse::success($this->qr->currentToken($qr));
    }

    public function regenerateQr(Request $request, Taxi $taxi): JsonResponse
    {
        abort_unless($taxi->city_id === $this->city()->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $qr = $this->qr->regenerate($taxi, $request->user(), $validated['reason']);

        $this->audit->log('taxi.qr_regenerated', $taxi, $request->user(), context: $validated);

        return ApiResponse::success($this->qr->currentToken($qr));
    }

    // ── assignments ─────────────────────────────────────────────────────────

    public function assignments(Taxi $taxi): JsonResponse
    {
        abort_unless($taxi->city_id === $this->city()->id, 404);

        $assignments = $taxi->assignments()
            ->with('driver.user')
            ->orderByDesc('starts_on')
            ->get();

        return ApiResponse::success(AssignmentResource::collection($assignments));
    }

    public function assignDriver(Request $request, Taxi $taxi): JsonResponse
    {
        abort_unless($taxi->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'driver_uuid' => ['required', 'uuid'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        $driver = Driver::forCity($this->city())->where('uuid', $validated['driver_uuid'])->firstOrFail();

        $assignment = TaxiAssignment::create([
            'taxi_id' => $taxi->id,
            'driver_id' => $driver->id,
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'] ?? null,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->log('taxi.driver_assigned', $taxi, $request->user(), context: [
            'driver_uuid' => $driver->uuid,
        ]);

        return ApiResponse::success(
            (new AssignmentResource($assignment->load('driver.user')))->resolve(),
            status: 201,
        );
    }

    public function revokeAssignment(Request $request, TaxiAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->taxi?->city_id === $this->city()->id, 404);

        $assignment->forceFill(['is_active' => false, 'ends_on' => today()])->save();

        $this->audit->log('taxi.driver_unassigned', $assignment->taxi, $request->user());

        return ApiResponse::success(['revoked' => true]);
    }

    // ── lines ───────────────────────────────────────────────────────────────

    public function lines(Request $request): JsonResponse
    {
        $lines = TaxiLine::forCity($this->city())->orderBy('code')->get();

        return ApiResponse::success(TaxiLineResource::collection($lines));
    }

    public function storeLine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32',
                Rule::unique('taxi_lines')->where(fn ($q) => $q->where('city_id', $this->city()->id))],
            'name' => ['required', 'string', 'max:150'],
            'origin_label' => ['nullable', 'string', 'max:150'],
            'destination_label' => ['nullable', 'string', 'max:150'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'flat_fare' => ['required', 'integer', 'min:0'],
            'origin_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'origin_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'destination_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'destination_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'typical_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
        ]);

        $line = TaxiLine::create($validated + ['city_id' => $this->city()->id, 'is_active' => true]);

        $this->audit->log('taxi.line.created', $line, $request->user(), after: $validated);

        return ApiResponse::success((new TaxiLineResource($line))->resolve(), status: 201);
    }

    public function updateLine(Request $request, TaxiLine $taxiLine): JsonResponse
    {
        abort_unless($taxiLine->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'origin_label' => ['nullable', 'string', 'max:150'],
            'destination_label' => ['nullable', 'string', 'max:150'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'flat_fare' => ['sometimes', 'integer', 'min:0'],
            'typical_duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'is_active' => ['boolean'],
        ]);

        $taxiLine->fill($validated);
        $this->audit->logChange('taxi.line.updated', $taxiLine, $request->user());
        $taxiLine->save();

        return ApiResponse::success((new TaxiLineResource($taxiLine))->resolve());
    }

    // ── tariffs ─────────────────────────────────────────────────────────────

    public function tariffs(Request $request): JsonResponse
    {
        $tariffs = TaxiTariff::forCity($this->city())
            ->orderByDesc('priority')
            ->get()
            ->map(fn (TaxiTariff $tariff) => [
                'id' => $tariff->id,
                'name' => $tariff->name,
                'service_type' => $tariff->service_type->value,
                'service_type_label' => $tariff->service_type->label(),
                'base_fare' => $tariff->base_fare,
                'per_km_fare' => $tariff->per_km_fare,
                'per_minute_waiting_fare' => $tariff->per_minute_waiting_fare,
                'minimum_fare' => $tariff->minimum_fare,
                'maximum_fare' => $tariff->maximum_fare,
                'waiting_speed_kmh' => $tariff->waiting_speed_kmh,
                'multiplier' => $tariff->multiplier,
                'valid_from_time' => $tariff->valid_from_time,
                'valid_to_time' => $tariff->valid_to_time,
                'priority' => $tariff->priority,
                'is_active' => $tariff->is_active,
            ]);

        return ApiResponse::success($tariffs);
    }

    public function storeTariff(Request $request): JsonResponse
    {
        $validated = $request->validate($this->tariffRules());

        $tariff = TaxiTariff::create($validated + [
            'city_id' => $this->city()->id,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->log('taxi.tariff.created', $tariff, $request->user(), after: $validated);

        return ApiResponse::success($tariff, status: 201);
    }

    public function updateTariff(Request $request, TaxiTariff $taxiTariff): JsonResponse
    {
        abort_unless($taxiTariff->city_id === $this->city()->id, 404);

        $validated = $request->validate($this->tariffRules(forUpdate: true));

        $taxiTariff->fill($validated);
        $this->audit->logChange('taxi.tariff.updated', $taxiTariff, $request->user());
        $taxiTariff->save();

        return ApiResponse::success($taxiTariff);
    }

    // ── operations ──────────────────────────────────────────────────────────

    /** Every taxi in the city, with the detail a dispatcher uses. */
    public function liveMap(Request $request): JsonResponse
    {
        $taxis = $this->live->forCity($this->city()->id);

        $byMode = [];

        foreach ($taxis as $taxi) {
            $mode = $taxi['service_type'] ?? 'unknown';
            $byMode[$mode] = ($byMode[$mode] ?? 0) + 1;
        }

        return ApiResponse::success($taxis, [
            'count' => count($taxis),
            'by_service_type' => $byMode,
        ]);
    }

    public function rides(Request $request): JsonResponse
    {
        $rides = TaxiRide::forCity($this->city())
            ->with(['taxi', 'line', 'driver.user'])
            ->when($request->filled('service_type'), fn ($q) => $q->where('service_type', $request->string('service_type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('created_at')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paginated(TaxiRideResource::collection($rides));
    }

    /** Taxi activity over a window, split by the three products. */
    public function report(Request $request): JsonResponse
    {
        $from = $request->date('from') ?? now()->subDays(30);
        $to = $request->date('to') ?? now();

        // Eager loaded because the busiest-line and top-driver groupings below
        // read through to both. Without this the report is fine on an empty
        // day and throws the moment there is anything to report.
        $rides = TaxiRide::forCity($this->city())
            ->with(['line', 'driver.user'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get();

        // Money that moved, not rides that ended: a line fare is revenue the
        // moment it is taken, even if the passenger is still in the car.
        $paid = $rides->where('fare_amount', '>', 0);

        return ApiResponse::success([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'ride_count' => $paid->count(),
            'gross' => (int) $paid->sum('fare_amount'),
            'commission' => (int) $paid->sum('commission_amount'),
            'distance_meters' => (int) $paid->sum('distance_meters'),
            'unpaid_count' => $rides->where('status', TaxiRideStatus::Unpaid)->count(),
            'unpaid_amount' => (int) $rides->where('status', TaxiRideStatus::Unpaid)->sum('outstanding_amount'),
            'by_service_type' => collect(TaxiServiceType::cases())->map(function (TaxiServiceType $mode) use ($paid) {
                $group = $paid->where('service_type', $mode);

                return [
                    'service_type' => $mode->value,
                    'label' => $mode->label(),
                    'ride_count' => $group->count(),
                    'gross' => (int) $group->sum('fare_amount'),
                ];
            })->values(),
            'busiest_lines' => $paid->whereNotNull('taxi_line_id')
                ->groupBy('taxi_line_id')
                ->map(fn ($group) => [
                    'line' => $group->first()->line?->code,
                    'name' => $group->first()->line?->name,
                    'ride_count' => $group->count(),
                    'gross' => (int) $group->sum('fare_amount'),
                ])
                ->sortByDesc('ride_count')
                ->take(10)
                ->values(),
            'top_drivers' => $paid->groupBy('driver_id')
                ->map(fn ($group) => [
                    'driver' => $group->first()->driver?->user?->name,
                    'ride_count' => $group->count(),
                    'gross' => (int) $group->sum('fare_amount'),
                ])
                ->sortByDesc('gross')
                ->take(10)
                ->values(),
        ]);
    }

    // ── settlements ─────────────────────────────────────────────────────────

    public function settlements(Request $request): JsonResponse
    {
        $settlements = TaxiSettlement::forCity($this->city())
            ->with('driver.user')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paginated(TaxiSettlementResource::collection($settlements));
    }

    public function approveSettlement(Request $request, TaxiSettlement $taxiSettlement): JsonResponse
    {
        abort_unless($taxiSettlement->city_id === $this->city()->id, 404);

        $approved = $this->settlements->approve($taxiSettlement, $request->user());

        $this->audit->log('taxi.settlement.approved', $taxiSettlement, $request->user(), context: [
            'net_amount' => $taxiSettlement->net_amount,
        ]);

        return ApiResponse::success((new TaxiSettlementResource($approved->load('driver.user')))->resolve());
    }

    public function paySettlement(Request $request, TaxiSettlement $taxiSettlement): JsonResponse
    {
        abort_unless($taxiSettlement->city_id === $this->city()->id, 404);

        $validated = $request->validate(['payment_reference' => ['required', 'string', 'max:120']]);

        $paid = $this->settlements->markPaid($taxiSettlement, $validated['payment_reference']);

        $this->audit->log('taxi.settlement.paid', $taxiSettlement, $request->user(), context: $validated);

        return ApiResponse::success((new TaxiSettlementResource($paid->load('driver.user')))->resolve());
    }

    public function rejectSettlement(Request $request, TaxiSettlement $taxiSettlement): JsonResponse
    {
        abort_unless($taxiSettlement->city_id === $this->city()->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $rejected = $this->settlements->reject($taxiSettlement, $request->user(), $validated['reason']);

        $this->audit->log('taxi.settlement.rejected', $taxiSettlement, $request->user(), context: $validated);

        return ApiResponse::success((new TaxiSettlementResource($rejected->load('driver.user')))->resolve());
    }

    /** @return array<string, array<int, mixed>> */
    private function tariffRules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:150'],
            'service_type' => [$required, Rule::in(TaxiServiceType::values())],
            'base_fare' => [$required, 'integer', 'min:0'],
            'per_km_fare' => ['nullable', 'integer', 'min:0'],
            'per_minute_waiting_fare' => ['nullable', 'integer', 'min:0'],
            'minimum_fare' => ['nullable', 'integer', 'min:0'],
            'maximum_fare' => ['nullable', 'integer', 'min:0', 'gte:minimum_fare'],
            'waiting_speed_kmh' => ['nullable', 'integer', 'min:1', 'max:30'],
            'multiplier' => ['nullable', 'numeric', 'min:0.1', 'max:10'],
            'valid_from_time' => ['nullable', 'date_format:H:i'],
            'valid_to_time' => ['nullable', 'date_format:H:i'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }
}
