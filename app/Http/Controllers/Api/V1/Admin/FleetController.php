<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fleet\Enums\BusStatus;
use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\BusAssignment;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Services\BusQrService;
use App\Domain\Identity\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AssignmentResource;
use App\Http\Resources\V1\BusResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FleetController extends Controller
{
    public function __construct(
        private readonly BusQrService $qr,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $buses = Bus::forCity($this->city())
            ->with(['currentDriver.user:id,first_name,last_name,display_name', 'defaultLine'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($sub) => $sub->where('bus_number', 'like', $term)->orWhere('plate', 'like', $term));
            })
            ->orderBy('bus_number')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paginated(BusResource::collection($buses));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bus_number' => [
                'required', 'string', 'max:32',
                Rule::unique('buses')->where(fn ($q) => $q->where('city_id', $this->city()->id)),
            ],
            'plate' => ['nullable', 'string', 'max:32'],
            'model' => ['nullable', 'string', 'max:120'],
            'manufacture_year' => ['nullable', 'integer', 'min:1980', 'max:'.(date('Y') + 1)],
            'capacity_seated' => ['required', 'integer', 'min:1', 'max:200'],
            'capacity_standing' => ['required', 'integer', 'min:0', 'max:200'],
            'has_air_conditioning' => ['boolean'],
            'is_accessible' => ['boolean'],
            'operator_id' => ['nullable', 'integer', 'exists:operators,id'],
            'default_line_id' => ['nullable', 'integer', 'exists:bus_lines,id'],
            'status' => ['nullable', Rule::in(BusStatus::values())],
        ]);

        $bus = Bus::create($validated + ['city_id' => $this->city()->id]);

        // A bus without a QR cannot host a shift or take a fare, so one is
        // issued immediately rather than left to a separate admin step.
        $qr = $this->qr->issueFor($bus);

        $this->audit->log('fleet.bus.created', $bus, $request->user(), after: $validated);

        return ApiResponse::success([
            // Refreshed so the columns the database defaulted — status above
            // all — are populated. Without it the resource reads a null enum
            // and the whole response 500s on a bus created without a status.
            'bus' => (new BusResource($bus->refresh()))->resolve(),
            'qr' => ['public_id' => $qr->public_id, 'version' => $qr->version],
        ], status: 201);
    }

    public function update(Request $request, Bus $bus): JsonResponse
    {
        abort_unless($bus->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'plate' => ['nullable', 'string', 'max:32'],
            'model' => ['nullable', 'string', 'max:120'],
            'capacity_seated' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'capacity_standing' => ['sometimes', 'integer', 'min:0', 'max:200'],
            'has_air_conditioning' => ['boolean'],
            'is_accessible' => ['boolean'],
            'status' => ['sometimes', Rule::in(BusStatus::values())],
            'default_line_id' => ['nullable', 'integer', 'exists:bus_lines,id'],
            'inspection_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $bus->fill($validated);
        $this->audit->logChange('fleet.bus.updated', $bus, $request->user());
        $bus->save();

        return ApiResponse::success((new BusResource($bus))->resolve());
    }

    /** The printable QR payload plus its current rotating token. */
    public function qrCode(Bus $bus): JsonResponse
    {
        abort_unless($bus->city_id === $this->city()->id, 404);

        $qr = $bus->activeQrCode;

        if ($qr === null) {
            $qr = $this->qr->issueFor($bus);
        }

        return ApiResponse::success([
            'bus_number' => $bus->bus_number,
            'public_id' => $qr->public_id,
            'version' => $qr->version,
            'activated_at' => $qr->activated_at?->toIso8601String(),
            // The display token, refreshed by the in-bus screen. A printed
            // sticker carries only the public id and a deep link to it.
            'current' => $this->qr->currentToken($qr),
            'deep_link' => url('/q/'.$qr->public_id),
        ]);
    }

    /** Rotate the credential after a sticker is copied, lost or reassigned. */
    public function regenerateQr(Request $request, Bus $bus): JsonResponse
    {
        abort_unless($bus->city_id === $this->city()->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $qr = $this->qr->regenerate($bus, $request->user(), $validated['reason']);

        $this->audit->log('fleet.bus.qr_regenerated', $bus, $request->user(), context: $validated);

        return ApiResponse::success([
            'public_id' => $qr->public_id,
            'version' => $qr->version,
            'deep_link' => url('/q/'.$qr->public_id),
        ]);
    }

    public function assignDriver(Request $request, Bus $bus): JsonResponse
    {
        abort_unless($bus->city_id === $this->city()->id, 404);

        // Addressed by uuid like everything else on the admin surface; the
        // numeric driver id is never published.
        $validated = $request->validate([
            'driver_uuid' => ['required', 'string', 'exists:drivers,uuid'],
            'bus_line_id' => ['nullable', 'integer', 'exists:bus_lines,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        $driver = Driver::forCity($this->city())->where('uuid', $validated['driver_uuid'])->firstOrFail();

        $assignment = BusAssignment::create([
            'bus_id' => $bus->id,
            'driver_id' => $driver->id,
            'bus_line_id' => $validated['bus_line_id'] ?? null,
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'] ?? null,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->log('fleet.bus.driver_assigned', $bus, $request->user(), after: $validated);

        return ApiResponse::success(
            (new AssignmentResource($assignment->load('driver.user', 'line')))->resolve(),
            status: 201,
        );
    }

    /** Assignments for one bus — what the panel lists beside it. */
    public function assignments(Bus $bus): JsonResponse
    {
        abort_unless($bus->city_id === $this->city()->id, 404);

        $assignments = $bus->assignments()
            ->with(['driver.user:id,first_name,last_name,display_name', 'line'])
            ->orderByDesc('is_active')
            ->orderByDesc('starts_on')
            ->limit(50)
            ->get();

        return ApiResponse::success(AssignmentResource::collection($assignments)->resolve());
    }

    public function revokeAssignment(Request $request, BusAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->bus?->city_id === $this->city()->id, 404);

        $assignment->forceFill(['is_active' => false, 'ends_on' => today()])->save();

        $this->audit->log('fleet.bus.assignment_revoked', $assignment->bus, $request->user());

        return ApiResponse::success(['revoked' => true]);
    }
}
