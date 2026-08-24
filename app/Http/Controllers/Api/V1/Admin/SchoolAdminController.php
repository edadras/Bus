<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Services\AuditLogger;
use App\Domain\SchoolTransport\Enums\SchoolContractStatus;
use App\Domain\SchoolTransport\Models\School;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Domain\SchoolTransport\Models\SchoolServiceRoute;
use App\Domain\SchoolTransport\Models\SchoolTrip;
use App\Domain\SchoolTransport\Models\SchoolVehicle;
use App\Domain\SchoolTransport\Services\SchoolCompanyService;
use App\Domain\SchoolTransport\Services\SchoolContractService;
use App\Domain\SchoolTransport\Services\SchoolLiveService;
use App\Domain\SchoolTransport\Services\SchoolTripService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SchoolCompanyResource;
use App\Http\Resources\V1\SchoolContractResource;
use App\Http\Resources\V1\SchoolInvoiceResource;
use App\Http\Resources\V1\SchoolRouteResource;
use App\Http\Resources\V1\SchoolTripResource;
use App\Http\Resources\V1\SchoolVehicleResource;
use App\Support\Api\ApiResponse;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * School transport, from the panel.
 *
 * Two audiences share these endpoints and are told apart by one permission. A
 * city administrator holds `school.admin` and sees everything; a company
 * manager holds only `school.manage` and every query below is narrowed to the
 * companies they actually belong to. The narrowing happens in `scopeToCompanies`
 * rather than in each method, so a new endpoint cannot forget it.
 */
class SchoolAdminController extends Controller
{
    public function __construct(
        private readonly SchoolCompanyService $companies,
        private readonly SchoolContractService $contracts,
        private readonly SchoolTripService $trips,
        private readonly SchoolLiveService $live,
        private readonly AuditLogger $audit,
    ) {}

    // ── companies ───────────────────────────────────────────────────────────

    public function companyIndex(Request $request): JsonResponse
    {
        $companies = SchoolCompany::forCity($this->city())
            ->withCount(['vehicles', 'routes'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->when(! $this->isCityAdmin($request), fn ($q) => $q->whereIn('id', $this->ownCompanyIds($request)))
            ->orderBy('name')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paginated(SchoolCompanyResource::collection($companies));
    }

    public function approveCompany(Request $request, SchoolCompany $schoolCompany): JsonResponse
    {
        $this->assertCityAdmin($request);
        abort_unless($schoolCompany->city_id === $this->city()->id, 404);

        $company = $this->companies->approve($schoolCompany, $request->user());

        $this->audit->log('school.company.approved', $schoolCompany, $request->user());

        return ApiResponse::success((new SchoolCompanyResource($company))->resolve());
    }

    public function rejectCompany(Request $request, SchoolCompany $schoolCompany): JsonResponse
    {
        $this->assertCityAdmin($request);
        abort_unless($schoolCompany->city_id === $this->city()->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $company = $this->companies->reject($schoolCompany, $request->user(), $validated['reason']);

        $this->audit->log('school.company.rejected', $schoolCompany, $request->user(), context: $validated);

        return ApiResponse::success((new SchoolCompanyResource($company))->resolve());
    }

    public function suspendCompany(Request $request, SchoolCompany $schoolCompany): JsonResponse
    {
        $this->assertCityAdmin($request);
        abort_unless($schoolCompany->city_id === $this->city()->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $company = $this->companies->suspend($schoolCompany, $request->user(), $validated['reason']);

        $this->audit->log('school.company.suspended', $schoolCompany, $request->user(), context: $validated);

        return ApiResponse::success((new SchoolCompanyResource($company))->resolve());
    }

    // ── schools ─────────────────────────────────────────────────────────────

    public function schools(Request $request): JsonResponse
    {
        $schools = School::forCity($this->city())->orderBy('name')->get();

        return ApiResponse::success($schools);
    }

    public function storeSchool(Request $request): JsonResponse
    {
        $this->assertCityAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150',
                Rule::unique('schools')->where(fn ($q) => $q->where('city_id', $this->city()->id))],
            'code' => ['nullable', 'string', 'max:32'],
            'gender' => ['nullable', Rule::in(['girls', 'boys', 'mixed'])],
            'level' => ['nullable', Rule::in(['primary', 'middle', 'high', 'other'])],
            'address' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'phone' => ['nullable', 'string', 'max:20'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i'],
        ]);

        $school = School::create($validated + ['city_id' => $this->city()->id, 'is_active' => true]);

        $this->audit->log('school.school.created', $school, $request->user(), after: $validated);

        return ApiResponse::success($school, status: 201);
    }

    // ── vehicles ────────────────────────────────────────────────────────────

    public function vehicles(Request $request): JsonResponse
    {
        $vehicles = SchoolVehicle::forCity($this->city())
            ->when($request->filled('company_uuid'), fn ($q) => $q->whereHas(
                'company',
                fn ($c) => $c->where('uuid', $request->string('company_uuid')),
            ))
            ->when(! $this->isCityAdmin($request), fn ($q) => $q->whereIn('school_company_id', $this->ownCompanyIds($request)))
            ->orderBy('plate')
            ->get();

        return ApiResponse::success(SchoolVehicleResource::collection($vehicles));
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_uuid' => ['required', 'uuid'],
            'plate' => ['required', 'string', 'max:32'],
            'model' => ['nullable', 'string', 'max:150'],
            'color' => ['nullable', 'string', 'max:32'],
            'manufacture_year' => ['nullable', 'integer', 'min:1980', 'max:2100'],
            'capacity' => ['required', 'integer', 'min:1', 'max:60'],
            'has_supervisor' => ['boolean'],
            'has_seatbelts' => ['boolean'],
            'has_air_conditioning' => ['boolean'],
            'insurance_expires_at' => ['nullable', 'date'],
            'inspection_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $company = $this->company($request, $validated['company_uuid']);

        $vehicle = SchoolVehicle::create(collect($validated)->except('company_uuid')->all() + [
            'school_company_id' => $company->id,
            'city_id' => $this->city()->id,
            'status' => 'active',
        ]);

        $this->audit->log('school.vehicle.created', $vehicle, $request->user());

        return ApiResponse::success((new SchoolVehicleResource($vehicle))->resolve(), status: 201);
    }

    public function updateVehicle(Request $request, SchoolVehicle $schoolVehicle): JsonResponse
    {
        $this->assertOwnCompany($request, $schoolVehicle->school_company_id);

        $validated = $request->validate([
            'model' => ['nullable', 'string', 'max:150'],
            'color' => ['nullable', 'string', 'max:32'],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'status' => ['sometimes', Rule::in(['active', 'maintenance', 'out_of_service'])],
            'has_supervisor' => ['boolean'],
            'has_seatbelts' => ['boolean'],
            'insurance_expires_at' => ['nullable', 'date'],
            'inspection_due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $schoolVehicle->fill($validated);
        $this->audit->logChange('school.vehicle.updated', $schoolVehicle, $request->user());
        $schoolVehicle->save();

        return ApiResponse::success((new SchoolVehicleResource($schoolVehicle))->resolve());
    }

    // ── routes ──────────────────────────────────────────────────────────────

    public function routes(Request $request): JsonResponse
    {
        $routes = SchoolServiceRoute::forCity($this->city())
            ->with(['school', 'vehicle', 'driver.user', 'company'])
            ->when(! $this->isCityAdmin($request), fn ($q) => $q->whereIn('school_company_id', $this->ownCompanyIds($request)))
            ->when($request->filled('company_uuid'), fn ($q) => $q->whereHas(
                'company',
                fn ($c) => $c->where('uuid', $request->string('company_uuid')),
            ))
            ->orderBy('name')
            ->get();

        return ApiResponse::success(SchoolRouteResource::collection($routes));
    }

    public function storeRoute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_uuid' => ['required', 'uuid'],
            'school_uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:32'],
            'shift' => ['nullable', Rule::in(['morning', 'afternoon', 'both'])],
            'capacity' => ['required', 'integer', 'min:1', 'max:60'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:1', 'max:7'],
            'pickup_starts_at' => ['nullable', 'date_format:H:i'],
            'dropoff_starts_at' => ['nullable', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $company = $this->company($request, $validated['company_uuid']);
        $school = School::forCity($this->city())->where('uuid', $validated['school_uuid'])->firstOrFail();

        $route = SchoolServiceRoute::create(
            collect($validated)->except(['company_uuid', 'school_uuid'])->all() + [
                'school_company_id' => $company->id,
                'school_id' => $school->id,
                'city_id' => $this->city()->id,
                'is_active' => true,
            ],
        );

        $this->audit->log('school.route.created', $route, $request->user());

        return ApiResponse::success(
            (new SchoolRouteResource($route->load(['school', 'company'])))->resolve(),
            status: 201,
        );
    }

    /**
     * Put a van and a driver on the route.
     *
     * The two together are what make a route able to run at all, so they are
     * one action: assigning a van and forgetting the driver leaves a route that
     * looks ready and is not.
     */
    public function assignRouteCrew(Request $request, SchoolServiceRoute $schoolServiceRoute): JsonResponse
    {
        $this->assertOwnCompany($request, $schoolServiceRoute->school_company_id);

        $validated = $request->validate([
            'vehicle_uuid' => ['nullable', 'uuid'],
            'driver_uuid' => ['nullable', 'uuid'],
            'supervisor_uuid' => ['nullable', 'uuid'],
        ]);

        if (isset($validated['vehicle_uuid'])) {
            $vehicle = SchoolVehicle::where('uuid', $validated['vehicle_uuid'])
                ->where('school_company_id', $schoolServiceRoute->school_company_id)
                ->firstOrFail();

            $schoolServiceRoute->school_vehicle_id = $vehicle->id;
        }

        if (isset($validated['driver_uuid'])) {
            $driver = Driver::forCity($this->city())->where('uuid', $validated['driver_uuid'])->firstOrFail();
            $schoolServiceRoute->driver_id = $driver->id;
        }

        $this->audit->logChange('school.route.crew_assigned', $schoolServiceRoute, $request->user());
        $schoolServiceRoute->save();

        return ApiResponse::success(
            (new SchoolRouteResource($schoolServiceRoute->load(['school', 'vehicle', 'driver.user'])))->resolve(),
        );
    }

    public function updateRoute(Request $request, SchoolServiceRoute $schoolServiceRoute): JsonResponse
    {
        $this->assertOwnCompany($request, $schoolServiceRoute->school_company_id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'shift' => ['sometimes', Rule::in(['morning', 'afternoon', 'both'])],
            'capacity' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:1', 'max:7'],
            'pickup_starts_at' => ['nullable', 'date_format:H:i'],
            'dropoff_starts_at' => ['nullable', 'date_format:H:i'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $schoolServiceRoute->fill($validated);
        $this->audit->logChange('school.route.updated', $schoolServiceRoute, $request->user());
        $schoolServiceRoute->save();

        return ApiResponse::success(
            (new SchoolRouteResource($schoolServiceRoute->load(['school', 'vehicle', 'driver.user'])))->resolve(),
        );
    }

    /** The children riding this route, which is the seat list. */
    public function routeContracts(Request $request, SchoolServiceRoute $schoolServiceRoute): JsonResponse
    {
        $this->assertOwnCompany($request, $schoolServiceRoute->school_company_id);

        $contracts = $schoolServiceRoute->contracts()
            ->with(['student', 'guardian'])
            ->where('status', SchoolContractStatus::Active->value)
            ->get();

        return ApiResponse::success(SchoolContractResource::collection($contracts));
    }

    // ── contracts ───────────────────────────────────────────────────────────

    public function contracts(Request $request): JsonResponse
    {
        $contracts = SchoolServiceContract::forCity($this->city())
            ->with(['student.school', 'guardian', 'company', 'school', 'route'])
            ->when(! $this->isCityAdmin($request), fn ($q) => $q->whereIn('school_company_id', $this->ownCompanyIds($request)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('company_uuid'), fn ($q) => $q->whereHas(
                'company',
                fn ($c) => $c->where('uuid', $request->string('company_uuid')),
            ))
            ->orderByDesc('created_at')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paginated(SchoolContractResource::collection($contracts));
    }

    public function acceptContract(Request $request, SchoolServiceContract $schoolServiceContract): JsonResponse
    {
        $this->assertOwnCompany($request, $schoolServiceContract->school_company_id);

        $validated = $request->validate([
            'fee_amount' => ['required', 'integer', 'min:0'],
            'payment_cycle' => ['nullable', Rule::in(['monthly', 'termly', 'yearly'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $contract = $this->contracts->accept(
            $schoolServiceContract,
            $request->user(),
            (int) $validated['fee_amount'],
            $validated['payment_cycle'] ?? 'monthly',
            $validated['note'] ?? null,
        );

        $this->audit->log('school.contract.accepted', $contract, $request->user(), context: $validated);

        return ApiResponse::success(
            (new SchoolContractResource($contract->load(['student', 'company', 'school'])))->resolve(),
        );
    }

    public function rejectContract(Request $request, SchoolServiceContract $schoolServiceContract): JsonResponse
    {
        $this->assertOwnCompany($request, $schoolServiceContract->school_company_id);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $contract = $this->contracts->reject($schoolServiceContract, $request->user(), $validated['reason']);

        return ApiResponse::success((new SchoolContractResource($contract))->resolve());
    }

    /** Give the child a seat on a specific van. */
    public function assignContractRoute(Request $request, SchoolServiceContract $schoolServiceContract): JsonResponse
    {
        $this->assertOwnCompany($request, $schoolServiceContract->school_company_id);

        $validated = $request->validate(['route_uuid' => ['required', 'uuid']]);

        $route = SchoolServiceRoute::where('uuid', $validated['route_uuid'])->firstOrFail();

        $contract = $this->contracts->assignRoute($schoolServiceContract, $route, $request->user());

        $this->audit->log('school.contract.route_assigned', $contract, $request->user(), context: [
            'route_uuid' => $route->uuid,
        ]);

        return ApiResponse::success(
            (new SchoolContractResource($contract->load(['student', 'company', 'route.vehicle', 'route.driver.user'])))->resolve(),
        );
    }

    public function billContract(Request $request, SchoolServiceContract $schoolServiceContract): JsonResponse
    {
        $this->assertOwnCompany($request, $schoolServiceContract->school_company_id);

        $invoice = $this->contracts->billCurrentPeriod($schoolServiceContract);

        if ($invoice === null) {
            throw DomainException::make('contract_not_billable', 422, [
                'status' => $schoolServiceContract->status->value,
            ]);
        }

        return ApiResponse::success(
            (new SchoolInvoiceResource($invoice))->resolve(),
            status: 201,
        );
    }

    // ── runs ────────────────────────────────────────────────────────────────

    public function trips(Request $request): JsonResponse
    {
        $date = $request->date('date') ?? today();

        $trips = SchoolTrip::forCity($this->city())
            ->with(['route.school', 'vehicle', 'driver.user'])
            ->whereDate('service_date', $date->toDateString())
            ->when(! $this->isCityAdmin($request), fn ($q) => $q->whereIn('school_company_id', $this->ownCompanyIds($request)))
            ->orderBy('direction')
            ->get();

        return ApiResponse::success(SchoolTripResource::collection($trips), [
            'date' => $date->toDateString(),
        ]);
    }

    /** Create the runs for a date, which is normally the scheduler's job. */
    public function scheduleTrips(Request $request): JsonResponse
    {
        $validated = $request->validate(['date' => ['nullable', 'date']]);

        $date = isset($validated['date']) ? $request->date('date') : today();

        $routes = SchoolServiceRoute::forCity($this->city())
            ->active()
            ->when(! $this->isCityAdmin($request), fn ($q) => $q->whereIn('school_company_id', $this->ownCompanyIds($request)))
            ->get();

        $created = 0;

        foreach ($routes as $route) {
            foreach ($this->trips->scheduleFor($route, $date) as $trip) {
                // Count what was built, not what was found: the toast this
                // number feeds says "created", and a second click must say 0.
                if ($trip->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        return ApiResponse::success([
            'date' => $date->toDateString(),
            'runs_created' => $created,
        ]);
    }

    /** Every school van moving in the city right now. */
    public function liveMap(Request $request): JsonResponse
    {
        $vans = $this->live->forCity($this->city()->id);

        if (! $this->isCityAdmin($request)) {
            $own = $this->ownCompanyIds($request);
            $vans = array_values(array_filter(
                $vans,
                static fn (array $state) => in_array($state['company_id'] ?? null, $own, true),
            ));
        }

        return ApiResponse::success($vans, ['count' => count($vans)]);
    }

    // ── scoping ─────────────────────────────────────────────────────────────

    private function isCityAdmin(Request $request): bool
    {
        return $request->user()->hasPermission('school.admin');
    }

    private function assertCityAdmin(Request $request): void
    {
        if (! $this->isCityAdmin($request)) {
            throw DomainException::make('forbidden', 403);
        }
    }

    /** @return array<int, int> */
    private function ownCompanyIds(Request $request): array
    {
        return $this->companies->companiesFor($request->user());
    }

    private function assertOwnCompany(Request $request, int $companyId): void
    {
        if ($this->isCityAdmin($request)) {
            return;
        }

        if (! in_array($companyId, $this->ownCompanyIds($request), true)) {
            throw DomainException::make('not_a_company_member', 403);
        }
    }

    private function company(Request $request, string $uuid): SchoolCompany
    {
        $company = SchoolCompany::forCity($this->city())->where('uuid', $uuid)->firstOrFail();

        $this->assertOwnCompany($request, $company->id);

        return $company;
    }
}
