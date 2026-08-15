<?php

namespace App\Domain\Merchant\Services;

use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Merchant\Models\MerchantTerminal;
use App\Support\Exceptions\QrValidationException;
use Illuminate\Support\Str;

/**
 * Merchant terminals reuse the bus QR scheme: a public id on the till plus a
 * rotating HMAC token. Sharing the primitive means one audited implementation
 * of signing, drift tolerance and replay defence rather than two.
 */
class MerchantTerminalQrService
{
    public function __construct(private readonly QrTokenService $tokens) {}

    public function issueTerminal(Merchant $merchant, string $name, ?string $location = null): MerchantTerminal
    {
        return $merchant->terminals()->create([
            'name' => $name,
            'public_id' => $this->generatePublicId(),
            'secret' => $this->tokens->generateSecret(),
            'location_label' => $location,
            'is_active' => true,
        ]);
    }

    public function currentToken(MerchantTerminal $terminal): array
    {
        return [
            'token' => $this->tokens->issue($terminal->public_id, $terminal->secret),
            'public_id' => $terminal->public_id,
            'expires_in' => $this->tokens->secondsUntilRotation(),
            'rotation_seconds' => (int) config('transit.qr.rotation_seconds'),
        ];
    }

    /** @return array{token: \App\Domain\Fleet\DTO\QrToken, terminal: MerchantTerminal} */
    public function resolveScan(string $rawToken): array
    {
        $token = $this->tokens->parse($rawToken);

        $terminal = MerchantTerminal::with('merchant')
            ->where('public_id', $token->publicId)
            ->first();

        if ($terminal === null) {
            throw new QrValidationException('unknown_code');
        }

        if (! $terminal->is_active) {
            throw new QrValidationException('revoked');
        }

        $this->tokens->verify($token, $terminal->secret);

        return ['token' => $token, 'terminal' => $terminal];
    }

    public function consume(\App\Domain\Fleet\DTO\QrToken $token): void
    {
        $this->tokens->consumeNonce($token, 'merchant');
    }

    public function release(\App\Domain\Fleet\DTO\QrToken $token): void
    {
        $this->tokens->releaseNonce($token, 'merchant');
    }

    public function rotateSecret(MerchantTerminal $terminal): MerchantTerminal
    {
        $terminal->forceFill(['secret' => $this->tokens->generateSecret()])->save();

        return $terminal;
    }

    private function generatePublicId(): string
    {
        do {
            $candidate = 'M'.Str::upper(Str::random(9));
        } while (MerchantTerminal::where('public_id', $candidate)->exists());

        return $candidate;
    }
}
