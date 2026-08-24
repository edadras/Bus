<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SchoolTransport\Enums\SchoolAttendanceStatus;
use App\Domain\SchoolTransport\Models\School;
use App\Domain\SchoolTransport\Models\SchoolCompany;
use App\Domain\SchoolTransport\Models\SchoolContractInvoice;
use App\Domain\SchoolTransport\Models\SchoolServiceContract;
use App\Domain\SchoolTransport\Models\SchoolStudent;
use App\Domain\SchoolTransport\Models\SchoolTripStudent;
use App\Domain\SchoolTransport\Services\SchoolAttendanceService;
use App\Domain\SchoolTransport\Services\SchoolContractService;
use App\Domain\SchoolTransport\Services\SchoolInvoiceService;
use App\Domain\SchoolTransport\Services\SchoolLiveService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\SchoolCompanyResource;
use App\Http\Resources\V1\SchoolContractResource;
use App\Http\Resources\V1\SchoolInvoiceResource;
use App\Http\Resources\V1\SchoolStudentResource;
use App\Http\Resources\V1\SchoolTripStudentResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The guardian's side of school transport.
 *
 * Everything here is scoped to the signed-in parent's own children, and the
 * live view is scoped harder still: to a run that is actually happening, and
 * only until their child's journey on it is over.
 */
class SchoolController extends Controller
{
    public function __construct(
        private readonly SchoolContractService $contracts,
        private readonly SchoolInvoiceService $invoices,
        private readonly SchoolLiveService $live,
        private readonly SchoolAttendanceService $attendance,
    ) {}

    /** The companies a parent may choose between: approved ones, and no others. */
    public function companies(Request $request): JsonResponse
    {
        $companies = SchoolCompany::forCity($this->city())
            ->approved()
            ->withCount(['vehicles', 'routes'])
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->orderBy('name')
            ->get();

        return ApiResponse::success(SchoolCompanyResource::collection($companies));
    }

    public function schools(Request $request): JsonResponse
    {
        $schools = School::forCity($this->city())
            ->active()
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->orderBy('name')
            ->get()
            ->map(fn (School $school) => [
                'uuid' => $school->uuid,
                'name' => $school->name,
                'gender' => $school->gender,
                'level' => $school->level,
                'address' => $school->address,
                'lat' => $school->lat,
                'lng' => $school->lng,
                'starts_at' => $school->starts_at,
                'ends_at' => $school->ends_at,
            ]);

        return ApiResponse::success($schools);
    }

    // ── the children ────────────────────────────────────────────────────────

    public function students(Request $request): JsonResponse
    {
        $students = SchoolStudent::with('school')
            ->where('guardian_user_id', $request->user()->id)
            ->orderBy('first_name')
            ->get();

        return ApiResponse::success(SchoolStudentResource::collection($students));
    }

    public function storeStudent(Request $request): JsonResponse
    {
        $validated = $request->validate($this->studentRules());

        $school = isset($validated['school_uuid'])
            ? School::forCity($this->city())->where('uuid', $validated['school_uuid'])->firstOrFail()
            : null;

        $student = SchoolStudent::create(collect($validated)->except('school_uuid')->all() + [
            'guardian_user_id' => $request->user()->id,
            'city_id' => $this->city()->id,
            'school_id' => $school?->id,
            'is_active' => true,
        ]);

        return ApiResponse::success(
            (new SchoolStudentResource($student->load('school')))->resolve(),
            status: 201,
        );
    }

    public function updateStudent(Request $request, SchoolStudent $schoolStudent): JsonResponse
    {
        abort_unless($schoolStudent->guardian_user_id === $request->user()->id, 404);

        $validated = $request->validate($this->studentRules(forUpdate: true));

        if (isset($validated['school_uuid'])) {
            $school = School::forCity($this->city())->where('uuid', $validated['school_uuid'])->firstOrFail();
            $schoolStudent->school_id = $school->id;
        }

        $schoolStudent->fill(collect($validated)->except('school_uuid')->all());
        $schoolStudent->save();

        return ApiResponse::success((new SchoolStudentResource($schoolStudent->load('school')))->resolve());
    }

    // ── contracts ───────────────────────────────────────────────────────────

    public function contracts(Request $request): JsonResponse
    {
        $contracts = SchoolServiceContract::with(['student.school', 'company', 'school', 'route.vehicle', 'route.driver.user'])
            ->where('guardian_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(SchoolContractResource::collection($contracts));
    }

    public function storeContract(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_uuid' => ['required', 'uuid'],
            'company_uuid' => ['required', 'uuid'],
            'direction' => ['nullable', Rule::in(['to_school', 'from_school', 'both'])],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:1', 'max:7'],
            'pickup_address' => ['nullable', 'string', 'max:255'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'guardian_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $student = SchoolStudent::where('uuid', $validated['student_uuid'])->firstOrFail();
        $company = SchoolCompany::forCity($this->city())->where('uuid', $validated['company_uuid'])->firstOrFail();

        $contract = $this->contracts->request($student, $company, $request->user(), $validated);

        return ApiResponse::success(
            (new SchoolContractResource($contract->load(['student', 'company', 'school'])))->resolve(),
            status: 201,
        );
    }

    public function endContract(Request $request, SchoolServiceContract $schoolServiceContract): JsonResponse
    {
        abort_unless($schoolServiceContract->guardian_user_id === $request->user()->id, 404);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        $contract = $this->contracts->end(
            $schoolServiceContract,
            $request->user(),
            $validated['reason'] ?? null,
        );

        return ApiResponse::success((new SchoolContractResource($contract))->resolve());
    }

    // ── invoices ────────────────────────────────────────────────────────────

    public function invoices(Request $request): JsonResponse
    {
        $invoices = SchoolContractInvoice::with('contract.student')
            ->whereHas('contract', fn ($q) => $q->where('guardian_user_id', $request->user()->id))
            ->orderByDesc('period_start')
            ->paginate(min(50, $request->integer('per_page', 20)));

        return ApiResponse::paginated(SchoolInvoiceResource::collection($invoices));
    }

    public function payInvoice(Request $request, SchoolContractInvoice $schoolContractInvoice): JsonResponse
    {
        $paid = $this->invoices->pay($schoolContractInvoice, $request->user());

        return ApiResponse::success((new SchoolInvoiceResource($paid->load('contract.student')))->resolve());
    }

    // ── watching the van ────────────────────────────────────────────────────

    /**
     * Where my child is right now.
     *
     * Answers with the run the child is on today, or nothing at all — and
     * "nothing" is a legitimate answer that the app shows as such rather than
     * as an error, because most of the day there is no van to watch.
     */
    public function live(Request $request, SchoolStudent $schoolStudent): JsonResponse
    {
        abort_unless($schoolStudent->guardian_user_id === $request->user()->id, 404);

        $row = SchoolTripStudent::with(['trip.route.school', 'trip.vehicle', 'trip.driver.user', 'student'])
            ->where('school_student_id', $schoolStudent->id)
            ->whereHas('trip', fn ($q) => $q->live())
            ->latest('id')
            ->first();

        if ($row === null) {
            return ApiResponse::success(null, [
                'reason' => 'no_run_in_progress',
            ]);
        }

        return ApiResponse::success($this->live->forGuardian($row, $request->user()));
    }

    /** What happened on the recent runs, which is what a parent checks at night. */
    public function attendance(Request $request, SchoolStudent $schoolStudent): JsonResponse
    {
        abort_unless($schoolStudent->guardian_user_id === $request->user()->id, 404);

        $rows = SchoolTripStudent::with(['trip.route'])
            ->where('school_student_id', $schoolStudent->id)
            ->whereHas('trip', fn ($q) => $q->orderByDesc('service_date'))
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (SchoolTripStudent $row) => [
                'uuid' => $row->uuid,
                'service_date' => $row->trip?->service_date?->toDateString(),
                'direction' => $row->trip?->direction->value,
                'direction_label' => $row->trip?->direction->label(),
                'status' => $row->status->value,
                'status_label' => $row->status->label(),
                'status_color' => $row->status->color(),
                'picked_up_at' => $row->picked_up_at?->toIso8601String(),
                'dropped_off_at' => $row->dropped_off_at?->toIso8601String(),
                'note' => $row->note,
            ]);

        return ApiResponse::success($rows);
    }

    /**
     * Tell the company the child is not travelling.
     *
     * A different claim from the driver marking absence at the door, made by a
     * different person, and the driver needs to see it before they drive there.
     */
    public function reportAbsence(Request $request, SchoolStudent $schoolStudent): JsonResponse
    {
        abort_unless($schoolStudent->guardian_user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:200'],
            'direction' => ['nullable', Rule::in(['to_school', 'from_school'])],
        ]);

        $rows = SchoolTripStudent::with('trip', 'student')
            ->where('school_student_id', $schoolStudent->id)
            ->where('status', SchoolAttendanceStatus::Pending->value)
            ->whereHas('trip', function ($q) use ($validated) {
                $q->whereDate('service_date', today())->open();

                if (isset($validated['direction'])) {
                    $q->where('direction', $validated['direction']);
                }
            })
            ->get();

        foreach ($rows as $row) {
            $this->attendance->reportAbsenceByGuardian($row, $request->user(), $validated['note'] ?? null);
        }

        return ApiResponse::success([
            'marked' => $rows->count(),
            'rows' => SchoolTripStudentResource::collection($rows->map->fresh())->resolve(),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function studentRules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes' : 'required';

        return [
            'first_name' => [$required, 'string', 'max:60'],
            'last_name' => [$required, 'string', 'max:60'],
            'school_uuid' => ['nullable', 'uuid'],
            'national_code' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date'],
            'grade' => ['nullable', 'string', 'max:32'],
            'classroom' => ['nullable', 'string', 'max:32'],
            'gender' => ['nullable', Rule::in(['female', 'male'])],
            'pickup_address' => ['nullable', 'string', 'max:255'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'medical_notes' => ['nullable', 'string', 'max:1000'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ];
    }
}
