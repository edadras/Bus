<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payment\Services\PaymentGatewayManager;
use App\Domain\Payment\Services\TopupService;
use App\Domain\Wallet\Models\Payment;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Wallet\TopupRequest;
use App\Http\Resources\V1\LedgerEntryResource;
use App\Http\Resources\V1\WalletResource;
use App\Support\Api\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly TopupService $topups,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->wallets->forUser($request->user());

        return ApiResponse::success([
            'wallet' => (new WalletResource($wallet))->resolve(),
            'limits' => [
                'min_topup' => (int) config('wallet.limits.min_topup'),
                'max_topup' => (int) config('wallet.limits.max_topup'),
                'max_balance' => (int) config('wallet.limits.max_balance'),
            ],
            'gateways' => $this->gateways->available(),
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $wallet = $this->wallets->forUser($request->user());

        $entries = $this->wallets->statement(
            $wallet,
            (int) ($validated['per_page'] ?? 20),
            $validated['type'] ?? null,
        );

        return ApiResponse::paginated(LedgerEntryResource::collection($entries));
    }

    public function topup(TopupRequest $request): JsonResponse
    {
        $result = $this->topups->initiate(
            user: $request->user(),
            amount: $request->integer('amount'),
            gateway: $request->string('gateway')->toString() ?: null,
            returnUrl: $request->string('return_url')->toString() ?: null,
        );

        return ApiResponse::success([
            'payment' => [
                'uuid' => $result['payment']->uuid,
                'amount' => $result['payment']->amount,
                'formatted_amount' => Money::format($result['payment']->amount),
                'status' => $result['payment']->status->value,
                'expires_at' => $result['payment']->expires_at?->toIso8601String(),
            ],
            'redirect' => [
                'url' => $result['redirect']->url,
                'method' => $result['redirect']->method,
                'fields' => $result['redirect']->fields,
            ],
        ], status: 201);
    }

    public function paymentStatus(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->user_id === $request->user()->id, 404);

        return ApiResponse::success([
            'uuid' => $payment->uuid,
            'status' => $payment->status->value,
            'status_label' => $payment->status->label(),
            'amount' => $payment->amount,
            'formatted_amount' => Money::format($payment->amount),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'failure_reason' => $payment->failure_reason,
            'balance' => $this->wallets->forUser($request->user())->balance,
        ]);
    }
}
