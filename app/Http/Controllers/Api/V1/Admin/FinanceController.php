<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuditLogger;
use App\Domain\Merchant\Enums\SettlementStatus;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Merchant\Models\Settlement;
use App\Domain\Merchant\Services\SettlementService;
use App\Domain\Wallet\Enums\TransactionType;
use App\Domain\Wallet\Models\FareRule;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\FareEngine;
use App\Domain\Wallet\Services\LedgerService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly LedgerService $ledger,
        private readonly SettlementService $settlements,
        private readonly FareEngine $fares,
        private readonly AuditLogger $audit,
    ) {}

    /** Revenue breakdown over a window. */
    public function summary(Request $request): JsonResponse
    {
        $from = $request->date('from') ?? now()->subDays(30);
        $to = $request->date('to') ?? now();

        $rows = WalletTransaction::forCity($this->city())
            ->completed()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) as count, COALESCE(SUM(amount),0) as total')
            ->get();

        return ApiResponse::success([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'by_type' => $rows->map(fn ($row) => [
                'type' => $row->type instanceof TransactionType ? $row->type->value : $row->type,
                'label' => TransactionType::from(
                    $row->type instanceof TransactionType ? $row->type->value : $row->type
                )->label(),
                'count' => (int) $row->count,
                'total' => (int) $row->total,
                'formatted' => Money::format((int) $row->total),
            ])->values(),
            'totals' => [
                'transactions' => (int) $rows->sum('count'),
                'volume' => (int) $rows->sum('total'),
            ],
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $transactions = WalletTransaction::forCity($this->city())
            ->with(['initiator:id,mobile,first_name,last_name,display_name'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('created_at')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paginated($transactions);
    }

    /**
     * Reverse a transaction. Never edits the original: a reversal is a new,
     * mirrored posting, which is what keeps the ledger auditable.
     */
    public function reverse(Request $request, WalletTransaction $walletTransaction): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);

        $reversal = $this->ledger->reverse($walletTransaction, $validated['reason']);

        $this->audit->log('finance.transaction.reversed', $walletTransaction, $request->user(), context: $validated);

        return ApiResponse::success([
            'reversal_uuid' => $reversal->uuid,
            'amount' => $reversal->amount,
            'original_uuid' => $walletTransaction->uuid,
        ]);
    }

    /** Manual credit or debit by finance staff; always audited. */
    public function adjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_uuid' => ['required', 'uuid', 'exists:users,uuid'],
            // Signed: positive credits the wallet, negative debits it.
            'amount' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'min:5', 'max:200'],
        ]);

        $user = User::where('uuid', $validated['user_uuid'])->firstOrFail();
        $wallet = $this->wallets->forUser($user);

        $transaction = $this->wallets->adjust(
            wallet: $wallet,
            amount: (int) $validated['amount'],
            reason: $validated['reason'],
            initiatedBy: $request->user(),
            idempotencyKey: 'adjust:'.Str::uuid(),
        );

        $this->audit->log('finance.wallet.adjusted', $wallet, $request->user(), context: $validated);

        return ApiResponse::success([
            'transaction_uuid' => $transaction->uuid,
            'balance' => $wallet->fresh()->balance,
        ]);
    }

    /** Ledger integrity: cached balances versus the sum of their postings. */
    public function auditWallet(Request $request): JsonResponse
    {
        $validated = $request->validate(['user_uuid' => ['required', 'uuid', 'exists:users,uuid']]);

        $user = User::where('uuid', $validated['user_uuid'])->firstOrFail();
        $wallet = $this->wallets->forUser($user);

        return ApiResponse::success([
            'wallet_uuid' => $wallet->uuid,
            'integrity' => $this->ledger->verify($wallet),
            'entry_count' => $wallet->ledgerEntries()->count(),
        ]);
    }

    public function fareRules(Request $request): JsonResponse
    {
        $rules = FareRule::forCity($this->city())
            ->with('line:id,code,name')
            ->orderByDesc('priority')
            ->get();

        return ApiResponse::success($rules);
    }

    public function storeFareRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:48',
                Rule::unique('fare_rules')->where(fn ($q) => $q->where('city_id', $this->city()->id))],
            'context' => ['required', Rule::in(['bus', 'merchant'])],
            'bus_line_id' => ['nullable', 'integer', 'exists:bus_lines,id'],
            'from_zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'to_zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'passenger_type' => ['nullable', Rule::in(['regular', 'student', 'senior', 'disabled', 'child'])],
            'base_fare' => ['required', 'integer', 'min:0'],
            'per_km_fare' => ['nullable', 'integer', 'min:0'],
            'min_fare' => ['nullable', 'integer', 'min:0'],
            'max_fare' => ['nullable', 'integer', 'min:0', 'gte:min_fare'],
            'multiplier' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'valid_from_time' => ['nullable', 'date_format:H:i'],
            'valid_to_time' => ['nullable', 'date_format:H:i'],
            'valid_days' => ['nullable', 'array'],
            'valid_days.*' => ['integer', 'min:1', 'max:7'],
            'valid_from_date' => ['nullable', 'date'],
            'valid_to_date' => ['nullable', 'date', 'after_or_equal:valid_from_date'],
        ]);

        $rule = FareRule::create($validated + [
            'city_id' => $this->city()->id,
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        $this->fares->flushCache($this->city()->id);

        $this->audit->log('finance.fare_rule.created', $rule, $request->user(), after: $validated);

        return ApiResponse::success($rule, status: 201);
    }

    public function updateFareRule(Request $request, FareRule $fareRule): JsonResponse
    {
        abort_unless($fareRule->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'base_fare' => ['sometimes', 'integer', 'min:0'],
            'per_km_fare' => ['sometimes', 'integer', 'min:0'],
            'min_fare' => ['nullable', 'integer', 'min:0'],
            'max_fare' => ['nullable', 'integer', 'min:0'],
            'multiplier' => ['sometimes', 'numeric', 'min:0', 'max:10'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['boolean'],
        ]);

        $fareRule->fill($validated);
        $this->audit->logChange('finance.fare_rule.updated', $fareRule, $request->user());
        $fareRule->save();

        $this->fares->flushCache($this->city()->id);

        return ApiResponse::success($fareRule);
    }

    public function settlements(Request $request): JsonResponse
    {
        $settlements = Settlement::query()
            ->whereHas('merchant', fn ($q) => $q->where('city_id', $this->city()->id))
            ->with('merchant:id,name,code,type')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paginated($settlements);
    }

    public function createSettlement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_uuid' => ['required', 'uuid', 'exists:merchants,uuid'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $merchant = Merchant::where('uuid', $validated['merchant_uuid'])->firstOrFail();

        abort_unless($merchant->city_id === $this->city()->id, 404);

        $settlement = $this->settlements->draft(
            merchant: $merchant,
            periodStart: $request->date('from'),
            periodEnd: $request->date('to'),
            requestedBy: $request->user(),
        );

        $this->audit->log('finance.settlement.created', $settlement, $request->user());

        return ApiResponse::success($settlement, status: 201);
    }

    public function approveSettlement(Request $request, Settlement $settlement): JsonResponse
    {
        abort_unless($settlement->merchant?->city_id === $this->city()->id, 404);

        $approved = $this->settlements->approve($settlement, $request->user());

        $this->audit->log('finance.settlement.approved', $settlement, $request->user(), context: [
            'net_amount' => $settlement->net_amount,
        ]);

        return ApiResponse::success($approved);
    }

    public function paySettlement(Request $request, Settlement $settlement): JsonResponse
    {
        abort_unless($settlement->merchant?->city_id === $this->city()->id, 404);

        $validated = $request->validate(['payment_reference' => ['required', 'string', 'max:120']]);

        $paid = $this->settlements->markPaid($settlement, $validated['payment_reference']);

        $this->audit->log('finance.settlement.paid', $settlement, $request->user(), context: $validated);

        return ApiResponse::success($paid);
    }

    public function rejectSettlement(Request $request, Settlement $settlement): JsonResponse
    {
        abort_unless($settlement->merchant?->city_id === $this->city()->id, 404);

        $validated = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        $rejected = $this->settlements->reject($settlement, $request->user(), $validated['reason']);

        $this->audit->log('finance.settlement.rejected', $settlement, $request->user(), context: $validated);

        return ApiResponse::success($rejected);
    }
}
