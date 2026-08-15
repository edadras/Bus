<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\BusQrCode;
use App\Domain\Identity\Models\User;
use App\Support\Exceptions\QrValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Lifecycle of a bus's QR credential: issue, rotate, revoke, resolve. */
class BusQrService
{
    public function __construct(private readonly QrTokenService $tokens) {}

    public function issueFor(Bus $bus): BusQrCode
    {
        return DB::transaction(function () use ($bus): BusQrCode {
            $bus->qrCodes()->where('is_active', true)->update([
                'is_active' => false,
                'revoked_at' => now(),
                'revoke_reason' => 'superseded',
            ]);

            $version = ((int) $bus->qrCodes()->max('version')) + 1;

            return $bus->qrCodes()->create([
                'public_id' => $this->generatePublicId(),
                'secret' => $this->tokens->generateSecret(),
                'version' => $version,
                'is_active' => true,
                'activated_at' => now(),
            ]);
        });
    }

    /** Rotate after a sticker is lost, photographed, or the bus is reassigned. */
    public function regenerate(Bus $bus, User $actor, string $reason = 'manual_regeneration'): BusQrCode
    {
        $bus->qrCodes()->where('is_active', true)->update([
            'is_active' => false,
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
            'revoke_reason' => $reason,
        ]);

        return $this->issueFor($bus);
    }

    public function revoke(BusQrCode $qr, User $actor, string $reason): void
    {
        $qr->forceFill([
            'is_active' => false,
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
            'revoke_reason' => $reason,
        ])->save();
    }

    /**
     * Turn a scanned payload into the bus it belongs to, verifying the
     * signature on the way. Throws on any failure; never returns a partial
     * result, so callers cannot accidentally trust an unverified bus.
     */
    public function resolveScan(string $rawToken): array
    {
        $token = $this->tokens->parse($rawToken);

        $qr = BusQrCode::with('bus')
            ->where('public_id', $token->publicId)
            ->first();

        if ($qr === null) {
            throw new QrValidationException('unknown_code');
        }

        if (! $qr->isUsable()) {
            throw new QrValidationException('revoked');
        }

        $this->tokens->verify($token, $qr->secret);

        if ($qr->bus === null) {
            throw new QrValidationException('unknown_code');
        }

        return ['token' => $token, 'qr' => $qr, 'bus' => $qr->bus];
    }

    /** Current display token for the screen inside a bus. */
    public function currentToken(BusQrCode $qr): array
    {
        return [
            'token' => $this->tokens->issue($qr->public_id, $qr->secret),
            'public_id' => $qr->public_id,
            'expires_in' => $this->tokens->secondsUntilRotation(),
            'rotation_seconds' => (int) config('transit.qr.rotation_seconds'),
        ];
    }

    private function generatePublicId(): string
    {
        do {
            $candidate = 'B'.Str::upper(Str::random(9));
        } while (BusQrCode::where('public_id', $candidate)->exists());

        return $candidate;
    }
}
