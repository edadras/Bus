<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\Wallet\Services\WalletService;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Token issuing and account provisioning.
 *
 * Sanctum abilities are scoped per client so a stolen passenger token cannot
 * drive a bus: the driver endpoints require the `driver` ability, which is only
 * ever minted for a user who actually holds an approved driver record.
 */
class AuthService
{
    public const ABILITY_PASSENGER = 'passenger';

    public const ABILITY_DRIVER = 'driver';

    public const ABILITY_MERCHANT = 'merchant';

    public const ABILITY_ADMIN = 'admin';

    public function __construct(private readonly WalletService $wallets) {}

    /** Find or create the passenger account behind a verified mobile number. */
    public function findOrCreatePassenger(string $mobile, ?City $city = null): User
    {
        return DB::transaction(function () use ($mobile, $city): User {
            $user = User::withTrashed()->where('mobile', $mobile)->first();

            if ($user !== null && $user->trashed()) {
                $user->restore();
            }

            if ($user === null) {
                $user = User::create([
                    'mobile' => $mobile,
                    'city_id' => $city?->id,
                    'status' => UserStatus::Active,
                    'locale' => $city?->locale ?? config('app.locale'),
                    'timezone' => $city?->timezone ?? config('app.timezone'),
                ]);

                $user->assignRole(Role::PASSENGER);
            }

            $user->forceFill([
                'mobile_verified_at' => $user->mobile_verified_at ?? now(),
                'last_login_at' => now(),
                'last_login_ip' => request()->ip(),
            ])->save();

            // Every account gets a wallet up front; a missing wallet at the
            // moment of payment would be a much worse failure.
            $this->wallets->forUser($user);

            return $user;
        });
    }

    public function assertCanAuthenticate(User $user): void
    {
        if (! $user->status->canAuthenticate()) {
            throw DomainException::make('account_'.$user->status->value, 403);
        }
    }

    /** Mint a token carrying only the abilities this user has actually earned. */
    public function issueToken(User $user, string $client, ?string $deviceName = null): NewAccessToken
    {
        $abilities = $this->abilitiesFor($user, $client);

        if ($abilities === []) {
            throw DomainException::make('client_not_permitted', 403, ['client' => $client]);
        }

        $name = $client.($deviceName !== null ? ':'.substr($deviceName, 0, 40) : '');

        return $user->createToken($name, $abilities, $this->expiryFor($client));
    }

    /** @return array<int, string> */
    public function abilitiesFor(User $user, string $client): array
    {
        $user->loadMissing('roles.permissions');

        return match ($client) {
            'passenger' => [self::ABILITY_PASSENGER],

            'driver' => $user->driver !== null && $user->driver->status->canDrive()
                ? [self::ABILITY_DRIVER, self::ABILITY_PASSENGER]
                : [],

            'merchant' => $user->merchantStaff()->where('is_active', true)->exists()
                || $user->hasRole(Role::MERCHANT_MANAGER)
                ? [self::ABILITY_MERCHANT]
                : [],

            'admin' => $user->hasRole(...Role::STAFF_ROLES)
                ? [self::ABILITY_ADMIN]
                : [],

            default => [],
        };
    }

    /** Staff tokens are short lived; passenger tokens are long lived. */
    private function expiryFor(string $client): ?\DateTimeInterface
    {
        return match ($client) {
            'admin' => now()->addHours(12),
            'merchant' => now()->addDays(7),
            'driver' => now()->addDays(30),
            default => now()->addDays(180),
        };
    }

    public function logout(User $user, bool $allDevices = false): void
    {
        if ($allDevices) {
            $user->tokens()->delete();

            return;
        }

        $user->currentAccessToken()?->delete();
    }
}
