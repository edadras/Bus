<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Support\Enums\ComplaintCategory;
use App\Domain\Support\Models\Complaint;
use App\Domain\Support\Services\ComplaintService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Support\CreateComplaintRequest;
use App\Http\Resources\V1\ComplaintResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplaintController extends Controller
{
    public function __construct(private readonly ComplaintService $complaints) {}

    public function categories(): JsonResponse
    {
        return ApiResponse::success(ComplaintCategory::options());
    }

    public function index(Request $request): JsonResponse
    {
        $complaints = Complaint::where('user_id', $request->user()->id)
            ->with(['line', 'bus:id,bus_number'])
            ->withCount('attachments')
            ->orderByDesc('created_at')
            ->paginate(min(50, $request->integer('per_page', 20)));

        return ApiResponse::paginated(ComplaintResource::collection($complaints));
    }

    public function store(CreateComplaintRequest $request): JsonResponse
    {
        $complaint = $this->complaints->create(
            user: $request->user(),
            data: $request->validated() + ['city_id' => $this->city()->id],
            attachments: $request->file('attachments', []),
        );

        return ApiResponse::success(
            (new ComplaintResource($complaint->load('publicMessages')))->resolve(),
            status: 201,
        );
    }

    public function show(Request $request, Complaint $complaint): JsonResponse
    {
        abort_unless($complaint->user_id === $request->user()->id, 404);

        $complaint->load(['publicMessages.author', 'line', 'bus:id,bus_number'])->loadCount('attachments');

        return ApiResponse::success((new ComplaintResource($complaint))->resolve());
    }

    public function reply(Request $request, Complaint $complaint): JsonResponse
    {
        abort_unless($complaint->user_id === $request->user()->id, 404);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:3'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $this->complaints->passengerReply($complaint, $validated['body'], $request->file('attachments', []));

        return ApiResponse::success(
            (new ComplaintResource($complaint->fresh()->load('publicMessages.author')))->resolve(),
        );
    }

    public function rate(Request $request, Complaint $complaint): JsonResponse
    {
        abort_unless($complaint->user_id === $request->user()->id, 404);

        $validated = $request->validate(['rating' => ['required', 'integer', 'min:1', 'max:5']]);

        return ApiResponse::success(
            (new ComplaintResource($this->complaints->rate($complaint, (int) $validated['rating'])))->resolve(),
        );
    }
}
