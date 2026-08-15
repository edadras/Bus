<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\OtpService;
use App\Domain\Wallet\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finds the person behind a wallet.
 *
 * Wallet adjustments and ledger audits are addressed by user UUID, and nothing
 * could turn "the passenger on 0912…" into one — which left both endpoints
 * unreachable from the panel however correct they were.
 *
 * Deliberately a lookup and not a user directory: it answers a specific search,
 * never lists everyone, is capped, and shows the full mobile only to a user who
 * holds `users.pii.view`. Everyone else sees the masked form, which is enough
 * to confirm they have the right person and not enough to harvest numbers.
 */
class UserLookupController extends Controller
{
    /** A search this short would match half the city. */
    private const MIN_TERM_LENGTH = 3;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly OtpService $otp,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:'.self::MIN_TERM_LENGTH, 'max:60'],
        ]);

        $term = trim($validated['q']);
        // A mobile typed as 0912…, ۰۹۱۲…, +98… or 912… is the same person; the
        // stored form is canonical, so normalise before matching.
        $mobile = $this->otp->normalizeMobile($term);
        $like = '%'.$term.'%';

        $users = User::forCity($this->city())
            ->where(function ($query) use ($like, $mobile, $term): void {
                $query->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('display_name', 'like', $like)
                    ->orWhere('uuid', $term);

                if ($mobile !== '') {
                    $query->orWhere('mobile', 'like', '%'.$mobile.'%');
                }
            })
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        $maySeeFullMobile = $request->user()->hasPermission('users.pii.view');

        return ApiResponse::success(
            $users->map(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'mobile' => $maySeeFullMobile ? $user->mobile : $user->maskedMobile(),
                'mobile_is_masked' => ! $maySeeFullMobile,
                'status' => $user->status?->value,
                // The balance is the reason anyone is searching here, so it is
                // fetched with the row rather than behind a second click.
                'balance' => $this->wallets->forUser($user)->balance,
            ])->values(),
        );
    }
}
