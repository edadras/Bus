<?php

namespace App\Domain\Taxi\Services;

use App\Domain\Fleet\DTO\QrToken;
use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Identity\Models\User;
use App\Domain\Taxi\Models\Taxi;
use App\Domain\Taxi\Models\TaxiQrCode;
use App\Support\Exceptions\QrValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lifecycle of a taxi's QR credential.
 *
 * The token machinery itself is shared with the bus fleet — same rotation, same
 * signature, same replay window — because the attack is identical: a
 * photographed sticker must be worth nothing. What differs is only the scope
 * the nonce is claimed under, so a bus token and a taxi token can never be
 * mistaken for one another even if their public ids collided.
 */
class TaxiQrService
{
    public const NONCE_SCOPE = 'taxi';

    public function __construct(private readonly QrTokenService $tokens) {}

    public function issueFor(Taxi $taxi): TaxiQrCode
    {
        return DB::transaction(function () use ($taxi): TaxiQrCode {
            $taxi->qrCodes()->where('is_active', true)->update([
                'is_active' => false,
                'revoked_at' => now(),
                'revoke_reason' => 'superseded',
            ]);

            $version = ((int) $taxi->qrCodes()->max('version')) + 1;

            return $taxi->qrCodes()->create([
                'public_id' => $this->generatePublicId(),
                'secret' => $this->tokens->generateSecret(),
                'version' => $version,
                'is_active' => true,
                'activated_at' => now(),
            ]);
        });
    }

    public function regenerate(Taxi $taxi, User $actor, string $reason = 'manual_regeneration'): TaxiQrCode
    {
        $taxi->qrCodes()->where('is_active', true)->update([
            'is_active' => false,
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
            'revoke_reason' => $reason,
        ]);

        return $this->issueFor($taxi);
    }

    /**
     * Turn a scanned payload into the taxi it belongs to, verifying on the way.
     *
     * @return array{token: QrToken, qr: TaxiQrCode, taxi: Taxi}
     */
    public function resolveScan(string $rawToken): array
    {
        $token = $this->tokens->parse($rawToken);

        $qr = TaxiQrCode::with('taxi')
            ->where('public_id', $token->publicId)
            ->first();

        if ($qr === null) {
            throw new QrValidationException('unknown_code');
        }

        if (! $qr->isUsable()) {
            throw new QrValidationException('revoked');
        }

        $this->tokens->verify($token, $qr->secret);

        if ($qr->taxi === null) {
            throw new QrValidationException('unknown_code');
        }

        return ['token' => $token, 'qr' => $qr, 'taxi' => $qr->taxi];
    }

    /** Current display token for the screen in the taxi. */
    public function currentToken(TaxiQrCode $qr): array
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
            // A distinct prefix so a taxi code is recognisable at a glance in
            // a support ticket, and never confusable with a bus sticker.
            $candidate = 'X'.Str::upper(Str::random(9));
        } while (TaxiQrCode::where('public_id', $candidate)->exists());

        return $candidate;
    }
}
