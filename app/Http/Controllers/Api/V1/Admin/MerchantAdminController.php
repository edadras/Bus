<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuditLogger;
use App\Domain\Identity\Services\OtpService;
use App\Domain\Merchant\Enums\MerchantStatus;
use App\Domain\Merchant\Enums\MerchantType;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Merchant\Services\MerchantTerminalQrService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MerchantAdminController extends Controller
{
    public function __construct(
        private readonly MerchantTerminalQrService $terminals,
        private readonly WalletService $wallets,
        private readonly AuditLogger $audit,
        private readonly OtpService $otp,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $merchants = Merchant::forCity($this->city())
            ->withCount(['terminals', 'transactions'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->orderBy('name')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return ApiResponse::paginated($merchants);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'type' => ['required', Rule::in(MerchantType::values())],
            'owner_mobile' => ['required', 'string', 'max:20'],
            'owner_first_name' => ['required', 'string', 'max:60'],
            'owner_last_name' => ['required', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'commission_bps' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'settlement_cycle' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])],
            'iban' => ['nullable', 'string', 'max:34'],
            'bank_account_holder' => ['nullable', 'string', 'max:150'],
            'max_transaction_amount' => ['nullable', 'integer', 'min:1000'],
        ]);

        $mobile = $this->otp->normalizeMobile($validated['owner_mobile']);

        if (! $this->otp->isValidMobile($mobile)) {
            return ApiResponse::error('invalid_mobile', null, 422);
        }

        $merchant = DB::transaction(function () use ($validated, $mobile): Merchant {
            $owner = User::firstOrCreate(
                ['mobile' => $mobile],
                [
                    'first_name' => $validated['owner_first_name'],
                    'last_name' => $validated['owner_last_name'],
                    'city_id' => $this->city()->id,
                    'mobile_verified_at' => now(),
                ],
            );

            $owner->assignRole(Role::MERCHANT_MANAGER, $this->city()->id);

            $merchant = Merchant::create([
                'city_id' => $this->city()->id,
                'owner_user_id' => $owner->id,
                'name' => $validated['name'],
                'legal_name' => $validated['legal_name'] ?? null,
                'code' => $this->generateCode($validated['name']),
                'type' => $validated['type'],
                'status' => MerchantStatus::PendingApproval,
                'phone' => $validated['phone'] ?? null,
                'email' => $validated['email'] ?? null,
                'address' => $validated['address'] ?? null,
                'lat' => $validated['lat'] ?? null,
                'lng' => $validated['lng'] ?? null,
                'commission_bps' => $validated['commission_bps'] ?? (int) config('wallet.settlement.default_commission_bps'),
                'settlement_cycle' => $validated['settlement_cycle'] ?? config('wallet.settlement.cycle'),
                'iban' => $validated['iban'] ?? null,
                'bank_account_holder' => $validated['bank_account_holder'] ?? null,
                'max_transaction_amount' => $validated['max_transaction_amount'] ?? null,
            ]);

            // The owner is automatically a manager-level staff member; without
            // this they could not use the merchant app on their own business.
            $merchant->staff()->create([
                'user_id' => $owner->id,
                'role' => 'manager',
                'can_refund' => true,
                'can_view_reports' => true,
                'is_active' => true,
            ]);

            $this->wallets->forMerchant($merchant);
            $this->terminals->issueTerminal($merchant, __('merchants.default_terminal'));

            return $merchant;
        });

        $this->audit->log('merchant.created', $merchant, $request->user(), after: ['name' => $validated['name']]);

        return ApiResponse::success($merchant->load(['terminals', 'wallet']), status: 201);
    }

    public function show(Merchant $merchant): JsonResponse
    {
        abort_unless($merchant->city_id === $this->city()->id, 404);

        $merchant->load(['owner:id,first_name,last_name,display_name,mobile', 'terminals', 'staff.user']);

        return ApiResponse::success([
            'merchant' => $merchant,
            'wallet' => $this->wallets->forMerchant($merchant),
            'staff' => $merchant->staff->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->user?->name,
                'mobile' => $s->user?->maskedMobile(),
                'role' => $s->role,
                'can_refund' => $s->can_refund,
                'is_active' => $s->is_active,
            ])->values(),
        ]);
    }

    public function changeStatus(Request $request, Merchant $merchant): JsonResponse
    {
        abort_unless($merchant->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(MerchantStatus::values())],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $status = MerchantStatus::from($validated['status']);

        $merchant->forceFill([
            'status' => $status,
            'approved_by' => $status === MerchantStatus::Active ? $request->user()->id : $merchant->approved_by,
            'approved_at' => $status === MerchantStatus::Active ? now() : $merchant->approved_at,
        ])->save();

        $this->audit->log('merchant.status_changed', $merchant, $request->user(), after: $validated);

        return ApiResponse::success($merchant);
    }

    public function addTerminal(Request $request, Merchant $merchant): JsonResponse
    {
        abort_unless($merchant->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'location_label' => ['nullable', 'string', 'max:150'],
        ]);

        $terminal = $this->terminals->issueTerminal($merchant, $validated['name'], $validated['location_label'] ?? null);

        $this->audit->log('merchant.terminal.created', $merchant, $request->user());

        return ApiResponse::success([
            'id' => $terminal->id,
            'name' => $terminal->name,
            'public_id' => $terminal->public_id,
        ], status: 201);
    }

    public function addStaff(Request $request, Merchant $merchant): JsonResponse
    {
        abort_unless($merchant->city_id === $this->city()->id, 404);

        $validated = $request->validate([
            'mobile' => ['required', 'string', 'max:20'],
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'role' => ['required', Rule::in(['manager', 'cashier'])],
            'can_refund' => ['boolean'],
            'can_view_reports' => ['boolean'],
        ]);

        $mobile = $this->otp->normalizeMobile($validated['mobile']);

        $user = User::firstOrCreate(
            ['mobile' => $mobile],
            [
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'city_id' => $this->city()->id,
                'mobile_verified_at' => now(),
            ],
        );

        $user->assignRole(Role::MERCHANT_STAFF, $this->city()->id);

        $staff = $merchant->staff()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'role' => $validated['role'],
                'can_refund' => $validated['can_refund'] ?? false,
                'can_view_reports' => $validated['can_view_reports'] ?? false,
                'is_active' => true,
            ],
        );

        $this->audit->log('merchant.staff.added', $merchant, $request->user(), after: ['role' => $validated['role']]);

        return ApiResponse::success(['id' => $staff->id, 'user_uuid' => $user->uuid], status: 201);
    }

    private function generateCode(string $name): string
    {
        $base = Str::upper(Str::substr(Str::slug($name, ''), 0, 6)) ?: 'MRC';

        do {
            $code = $base.Str::upper(Str::random(4));
        } while (Merchant::where('code', $code)->exists());

        return $code;
    }
}
