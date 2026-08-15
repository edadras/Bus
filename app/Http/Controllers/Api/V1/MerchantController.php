<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Merchant\Models\Merchant;
use App\Domain\Merchant\Models\MerchantStaff;
use App\Domain\Merchant\Models\MerchantTransaction;
use App\Domain\Merchant\Services\MerchantPaymentService;
use App\Domain\Merchant\Services\MerchantTerminalQrService;
use App\Domain\Merchant\Services\SettlementService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Merchant\ChargeRequest;
use App\Http\Resources\V1\MerchantTransactionResource;
use App\Http\Resources\V1\WalletResource;
use App\Support\Api\ApiResponse;
use App\Support\Exceptions\DomainException;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The merchant app: a pool, gym or shop accepting the city wallet.
 *
 * The staff member's own permissions (refunds, reports) come from the
 * merchant_staff pivot, so a cashier and a manager use the same app with
 * different capabilities and no client-side trust.
 */
class MerchantController extends Controller
{
    public function __construct(
        private readonly MerchantPaymentService $payments,
        private readonly MerchantTerminalQrService $terminalQr,
        private readonly SettlementService $settlements,
        private readonly WalletService $wallets,
    ) {}

    public function state(Request $request): JsonResponse
    {
        $staff = $this->staff($request);
        $merchant = $staff->merchant;
        $wallet = $this->wallets->forMerchant($merchant);

        return ApiResponse::success([
            'merchant' => [
                'uuid' => $merchant->uuid,
                'name' => $merchant->name,
                'code' => $merchant->code,
                'type' => $merchant->type->value,
                'type_label' => $merchant->type->label(),
                'status' => $merchant->status->value,
                'commission_bps' => $merchant->commission_bps,
                'allows_refund' => $merchant->allows_refund,
            ],
            'staff' => [
                'role' => $staff->role,
                'can_refund' => $staff->can_refund,
                'can_view_reports' => $staff->can_view_reports,
            ],
            'wallet' => (new WalletResource($wallet))->resolve(),
            'pending_settlement' => $this->settlements->pendingBalance($merchant),
            'terminals' => $merchant->terminals()->where('is_active', true)->get()
                ->map(fn ($terminal) => [
                    'id' => $terminal->id,
                    'name' => $terminal->name,
                    'public_id' => $terminal->public_id,
                    'location' => $terminal->location_label,
                ])->values(),
        ]);
    }

    /** Rotating QR for a till, refreshed by the app every few seconds. */
    public function terminalToken(Request $request, int $terminalId): JsonResponse
    {
        $staff = $this->staff($request);

        $terminal = $staff->merchant->terminals()->where('id', $terminalId)->firstOrFail();

        return ApiResponse::success($this->terminalQr->currentToken($terminal));
    }

    /**
     * Passenger-side call: scan the till's QR and pay. Lives on the merchant
     * controller because it is the merchant flow, but it is authorised as the
     * paying passenger, not as merchant staff.
     */
    public function charge(ChargeRequest $request): JsonResponse
    {
        $transaction = $this->payments->charge(
            payer: $request->user(),
            rawToken: $request->string('token')->toString(),
            amount: $request->integer('amount'),
            description: $request->string('description')->toString() ?: null,
        );

        return ApiResponse::success([
            'transaction' => (new MerchantTransactionResource($transaction->load('merchant')))->resolve(),
            'balance' => [
                'amount' => $this->wallets->forUser($request->user())->fresh()->balance,
                'formatted' => Money::format($this->wallets->forUser($request->user())->fresh()->balance),
            ],
        ], status: 201);
    }

    public function transactions(Request $request): JsonResponse
    {
        $staff = $this->staff($request);

        $transactions = MerchantTransaction::where('merchant_id', $staff->merchant_id)
            ->with(['user:id,mobile,first_name,last_name,display_name', 'terminal:id,name'])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('created_at')
            ->paginate(min(50, $request->integer('per_page', 20)));

        return ApiResponse::paginated(MerchantTransactionResource::collection($transactions));
    }

    public function refund(Request $request, MerchantTransaction $merchantTransaction): JsonResponse
    {
        $staff = $this->staff($request);

        abort_unless($merchantTransaction->merchant_id === $staff->merchant_id, 404);

        if (! $staff->can_refund) {
            throw DomainException::make('refund_not_permitted', 403);
        }

        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $refunded = $this->payments->refund($merchantTransaction, $request->user(), $validated['reason']);

        return ApiResponse::success((new MerchantTransactionResource($refunded))->resolve());
    }

    public function salesReport(Request $request): JsonResponse
    {
        $staff = $this->staff($request);

        if (! $staff->can_view_reports && ! $staff->isManager()) {
            throw DomainException::make('reports_not_permitted', 403);
        }

        $from = $request->date('from') ?? now()->subDays(30);
        $to = $request->date('to') ?? now();

        $rows = MerchantTransaction::where('merchant_id', $staff->merchant_id)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as transactions')
            ->selectRaw('COALESCE(SUM(amount),0) as gross')
            ->selectRaw('COALESCE(SUM(commission_amount),0) as commission')
            ->selectRaw('COALESCE(SUM(net_amount),0) as net')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return ApiResponse::success([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'daily' => $rows,
            'totals' => [
                'transactions' => (int) $rows->sum('transactions'),
                'gross' => (int) $rows->sum('gross'),
                'commission' => (int) $rows->sum('commission'),
                'net' => (int) $rows->sum('net'),
            ],
            'pending_settlement' => $this->settlements->pendingBalance($staff->merchant),
        ]);
    }

    public function requestSettlement(Request $request): JsonResponse
    {
        $staff = $this->staff($request);

        if (! $staff->isManager()) {
            throw DomainException::make('settlement_not_permitted', 403);
        }

        $settlement = $this->settlements->draft(
            merchant: $staff->merchant,
            periodStart: $request->date('from') ?? now()->subWeek()->startOfDay(),
            periodEnd: $request->date('to') ?? now(),
            requestedBy: $request->user(),
        );

        return ApiResponse::success([
            'reference' => $settlement->reference,
            'status' => $settlement->status->value,
            'gross_amount' => $settlement->gross_amount,
            'commission_amount' => $settlement->commission_amount,
            'net_amount' => $settlement->net_amount,
            'formatted_net' => $settlement->formattedNet(),
            'transaction_count' => $settlement->transaction_count,
        ], status: 201);
    }

    private function staff(Request $request): MerchantStaff
    {
        $staff = $request->user()->merchantStaff()
            ->where('is_active', true)
            ->with('merchant')
            ->first();

        if ($staff === null || $staff->merchant === null) {
            throw DomainException::make('not_merchant_staff', 403);
        }

        if (! $staff->merchant->status->canAcceptPayments()) {
            throw DomainException::make('merchant_not_active', 403);
        }

        return $staff;
    }
}
