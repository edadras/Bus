<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fleet\Enums\DriverDocumentType;
use App\Domain\Fleet\Enums\DriverStatus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuditLogger;
use App\Domain\Identity\Services\OtpService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DriverResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Driver administration.
 *
 * Drivers can never self-register: the account, the driver record and the role
 * are all created here by an authorised administrator, and the driver only
 * becomes able to start a shift once explicitly approved.
 */
class DriverAdminController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OtpService $otp,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $drivers = Driver::forCity($this->city())
            ->with(['user:id,first_name,last_name,display_name,mobile', 'operator:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($sub) => $sub->where('national_code', 'like', $term)
                    ->orWhere('employee_code', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('mobile', 'like', $term)
                        ->orWhere('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)));
            })
            ->orderByDesc('created_at')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paginated(DriverResource::collection($drivers));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'mobile' => ['required', 'string', 'max:20'],
            'national_code' => ['required', 'string', 'max:20'],
            'license_number' => ['required', 'string', 'max:32'],
            'license_class' => ['nullable', 'string', 'max:16'],
            'license_expires_at' => ['nullable', 'date', 'after:today'],
            'employee_code' => ['nullable', 'string', 'max:32'],
            'operator_id' => ['nullable', 'integer', 'exists:operators,id'],
            'hired_at' => ['nullable', 'date'],
            'contract_ends_at' => ['nullable', 'date', 'after:hired_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $mobile = $this->otp->normalizeMobile($validated['mobile']);

        if (! $this->otp->isValidMobile($mobile)) {
            return ApiResponse::error('invalid_mobile', null, 422);
        }

        $driver = DB::transaction(function () use ($validated, $mobile): Driver {
            // Reuse an existing passenger account when the number already has
            // one, rather than creating a second identity for the same person.
            $user = User::firstOrCreate(
                ['mobile' => $mobile],
                [
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'city_id' => $this->city()->id,
                ],
            );

            // An administrator entering the number vouches for it, so the
            // driver never has to prove it by SMS before their first sign-in.
            // Written with forceFill because `mobile_verified_at` is guarded on
            // purpose: nothing should be able to mass-assign a verification.
            if ($user->mobile_verified_at === null) {
                $user->forceFill(['mobile_verified_at' => now()])->save();
            }

            $user->assignRole(Role::DRIVER, $this->city()->id);

            return Driver::create([
                'user_id' => $user->id,
                'city_id' => $this->city()->id,
                'operator_id' => $validated['operator_id'] ?? null,
                'employee_code' => $validated['employee_code'] ?? null,
                'national_code' => $validated['national_code'],
                'license_number' => $validated['license_number'],
                'license_class' => $validated['license_class'] ?? null,
                'license_expires_at' => $validated['license_expires_at'] ?? null,
                // Explicitly not active: approval is a separate, audited step.
                'status' => DriverStatus::PendingApproval,
                'hired_at' => $validated['hired_at'] ?? today(),
                'contract_ends_at' => $validated['contract_ends_at'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        $this->audit->log('fleet.driver.created', $driver, $request->user(), after: [
            'national_code' => '[redacted]',
            'mobile' => $mobile,
        ]);

        return ApiResponse::success(
            (new DriverResource($driver->load('user')))->resolve(),
            status: 201,
        );
    }

    public function show(Driver $driver): JsonResponse
    {
        abort_unless($driver->city_id === $this->city()->id, 404);

        $driver->load(['user', 'operator', 'documents', 'assignments.bus', 'assignments.line']);

        return ApiResponse::success([
            'driver' => (new DriverResource($driver))->resolve(),
            'documents' => $driver->documents->map(fn ($doc) => [
                'id' => $doc->id,
                'type' => $doc->type->value,
                'type_label' => $doc->type->label(),
                'original_name' => $doc->original_name,
                'expires_at' => $doc->expires_at?->toDateString(),
                'is_expired' => $doc->isExpired(),
                'is_verified' => $doc->is_verified,
            ])->values(),
            'assignments' => $driver->assignments->map(fn ($a) => [
                'id' => $a->id,
                'bus_number' => $a->bus?->bus_number,
                'line' => $a->line?->code,
                'starts_on' => $a->starts_on->toDateString(),
                'ends_on' => $a->ends_on?->toDateString(),
                'is_active' => $a->is_active,
            ])->values(),
            'recent_shifts' => $driver->shifts()->latest('started_at')->limit(10)->get()
                ->map(fn ($s) => [
                    'started_at' => $s->started_at->toIso8601String(),
                    'ended_at' => $s->ended_at?->toIso8601String(),
                    'duration_minutes' => $s->durationMinutes(),
                    'trips' => $s->trip_count,
                    'passengers' => $s->passenger_count,
                ])->values(),
        ]);
    }

    public function update(Request $request, Driver $driver): JsonResponse
    {
        abort_unless($driver->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'license_number' => ['sometimes', 'string', 'max:32'],
            'license_class' => ['nullable', 'string', 'max:16'],
            'license_expires_at' => ['nullable', 'date'],
            'employee_code' => ['nullable', 'string', 'max:32'],
            'operator_id' => ['nullable', 'integer', 'exists:operators,id'],
            'contract_ends_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $driver->fill($validated);
        $this->audit->logChange('fleet.driver.updated', $driver, $request->user());
        $driver->save();

        return ApiResponse::success((new DriverResource($driver->load('user')))->resolve());
    }

    /** Approve, suspend or deactivate. This is what gates the driver app. */
    public function changeStatus(Request $request, Driver $driver): JsonResponse
    {
        abort_unless($driver->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(DriverStatus::values())],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $status = DriverStatus::from($validated['status']);
        $previous = $driver->status;

        $driver->forceFill([
            'status' => $status,
            'approved_by' => $status === DriverStatus::Active ? $request->user()->id : $driver->approved_by,
            'approved_at' => $status === DriverStatus::Active ? now() : $driver->approved_at,
        ])->save();

        // Suspending a driver must also cut their live session, otherwise the
        // token they already hold keeps working until it expires.
        if (! $status->canDrive()) {
            $driver->user?->tokens()->delete();
        }

        $this->audit->log('fleet.driver.status_changed', $driver, $request->user(),
            before: ['status' => $previous->value],
            after: ['status' => $status->value],
            context: ['reason' => $validated['reason'] ?? null],
        );

        return ApiResponse::success((new DriverResource($driver->load('user')))->resolve());
    }

    public function uploadDocument(Request $request, Driver $driver): JsonResponse
    {
        abort_unless($driver->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'type' => ['required', Rule::in(DriverDocumentType::values())],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $file = $request->file('file');
        // Private disk: licence scans and ID cards are never publicly served.
        $path = $file->store("drivers/{$driver->id}", 'local');

        $document = $driver->documents()->create([
            'type' => $validated['type'],
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'issued_at' => $validated['issued_at'] ?? null,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        $this->audit->log('fleet.driver.document_uploaded', $driver, $request->user(), context: [
            'type' => $validated['type'],
        ]);

        return ApiResponse::success(['id' => $document->id, 'type' => $document->type->value], status: 201);
    }

    /**
     * A short-lived signed URL for one document.
     *
     * The files sit on the private disk — a licence scan and a national card
     * are not things to serve from a guessable path — so approving a driver
     * means looking at the document through a link that expires.
     */
    public function document(Request $request, Driver $driver, int $documentId): JsonResponse
    {
        abort_unless($driver->city_id === $this->city()->id, 404);

        $document = $driver->documents()->findOrFail($documentId);

        $this->audit->log('fleet.driver.document_viewed', $driver, $request->user(), context: [
            'document_id' => $document->id,
            'type' => $document->type->value,
        ]);

        return ApiResponse::success([
            'url' => URL::temporarySignedRoute(
                'admin.drivers.document.download',
                now()->addMinutes(10),
                ['driver' => $driver->uuid, 'document' => $document->id],
            ),
            'expires_in' => 600,
        ]);
    }
}
