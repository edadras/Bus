<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\DTO\QrToken;
use App\Support\Exceptions\QrValidationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Rotating, signed QR tokens.
 *
 * A printed sticker alone is trivially copied: photograph it once and you can
 * "board" any bus from anywhere. So the sticker only carries the public id,
 * and a valid scan must present a token that is:
 *
 *   v1.<public_id>.<time_step>.<nonce>.<hmac>
 *
 * where the HMAC is taken over the first four fields with the bus's server-side
 * secret. The time step is a coarse clock (default 30s) so a token stops
 * verifying shortly after it is produced, and the nonce is remembered for the
 * replay window so the very same token cannot be presented twice — which is
 * what stops one passenger's screenshot from paying for a whole queue.
 *
 * Signature comparison is constant time; failures never say which field was
 * wrong beyond a coarse reason code.
 */
class QrTokenService
{
    private const SEPARATOR = '.';

    /** Mint a token. Only ever called server-side, for the display in the bus. */
    public function issue(string $publicId, string $secret, ?int $at = null): string
    {
        $version = (int) config('transit.qr.version', 1);
        $step = $this->timeStep($at);
        $nonce = Str::lower(Str::random(12));

        $payload = $this->payload($version, $publicId, $step, $nonce);

        return $payload.self::SEPARATOR.$this->sign($payload, $secret);
    }

    public function parse(string $raw): QrToken
    {
        $raw = trim($raw);

        // Tolerate a full URL form so a generic camera app can open the PWA.
        if (str_contains($raw, '/q/')) {
            $raw = Str::afterLast($raw, '/q/');
        }

        $parts = explode(self::SEPARATOR, $raw);

        if (count($parts) !== 5) {
            throw new QrValidationException('malformed');
        }

        [$version, $publicId, $step, $nonce, $signature] = $parts;

        if (! ctype_digit(ltrim($version, 'v')) || ! ctype_digit($step)) {
            throw new QrValidationException('malformed');
        }

        return new QrToken(
            version: (int) ltrim($version, 'v'),
            publicId: $publicId,
            timeStep: (int) $step,
            nonce: $nonce,
            signature: $signature,
            raw: $raw,
        );
    }

    /**
     * Verify signature and freshness. Does NOT consume the nonce — the caller
     * consumes it only once the surrounding business rules have also passed,
     * so a rejected boarding does not burn a token the passenger must re-scan.
     */
    public function verify(QrToken $token, string $secret, ?int $at = null): void
    {
        if ($token->version !== (int) config('transit.qr.version', 1)) {
            throw new QrValidationException('unsupported_version');
        }

        $currentStep = $this->timeStep($at);
        $drift = (int) config('transit.qr.drift_steps', 1);

        if (abs($currentStep - $token->timeStep) > $drift) {
            throw new QrValidationException('expired');
        }

        $expected = $this->sign(
            $this->payload($token->version, $token->publicId, $token->timeStep, $token->nonce),
            $secret,
        );

        if (! hash_equals($expected, $token->signature)) {
            throw new QrValidationException('invalid_signature');
        }
    }

    /**
     * Atomically claim the nonce. Cache::add is a compare-and-set, so two
     * concurrent requests carrying the same token cannot both win — the loser
     * gets a replay error rather than a second free ride.
     */
    public function consumeNonce(QrToken $token, string $scope = 'boarding'): void
    {
        $key = "qr:nonce:$scope:{$token->publicId}:{$token->nonce}";
        $ttl = (int) config('transit.qr.replay_window_seconds', 180);

        if (! Cache::add($key, now()->timestamp, $ttl)) {
            throw new QrValidationException('replayed');
        }
    }

    /** Release a claimed nonce when the surrounding transaction rolls back. */
    public function releaseNonce(QrToken $token, string $scope = 'boarding'): void
    {
        Cache::forget("qr:nonce:$scope:{$token->publicId}:{$token->nonce}");
    }

    public function generateSecret(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** Seconds remaining before the current token stops verifying. */
    public function secondsUntilRotation(?int $at = null): int
    {
        $rotation = (int) config('transit.qr.rotation_seconds', 30);
        $now = $at ?? now()->timestamp;

        return $rotation - ($now % $rotation);
    }

    private function timeStep(?int $at = null): int
    {
        $rotation = max(1, (int) config('transit.qr.rotation_seconds', 30));

        return intdiv($at ?? now()->timestamp, $rotation);
    }

    private function payload(int $version, string $publicId, int $step, string $nonce): string
    {
        return implode(self::SEPARATOR, ['v'.$version, $publicId, $step, $nonce]);
    }

    private function sign(string $payload, string $secret): string
    {
        // Truncated to 128 bits: still far beyond forgeable, and keeps the
        // printed/displayed token short enough to scan reliably.
        return substr(hash_hmac('sha256', $payload, $secret), 0, 32);
    }
}
