<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Analytics\Services\ReportService;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function transport(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return ApiResponse::success(
            $this->reports->transport($this->city(), $from, $to),
            $this->meta($from, $to),
        );
    }

    public function drivers(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return ApiResponse::success(
            $this->reports->drivers($this->city(), $from, $to),
            $this->meta($from, $to),
        );
    }

    public function passengers(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return ApiResponse::success(
            $this->reports->passengers($this->city(), $from, $to),
            $this->meta($from, $to),
        );
    }

    public function revenue(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return ApiResponse::success(
            $this->reports->revenue($this->city(), $from, $to),
            $this->meta($from, $to),
        );
    }

    /** @return array{0: CarbonInterface, 1: CarbonInterface} */
    private function period(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return [
            ($request->date('from') ?? now()->subDays(29))->startOfDay(),
            ($request->date('to') ?? now())->endOfDay(),
        ];
    }

    private function meta(CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => (int) $from->diffInDays($to) + 1,
            ],
        ];
    }
}
