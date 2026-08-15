<?php

namespace Tests\Unit;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Services\BusQrService;
use App\Domain\Fleet\Services\QrTokenService;
use App\Support\Exceptions\QrValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A copied photograph of a bus sticker must not be worth a free ride. These
 * tests pin the properties that make that true.
 */
class QrTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private QrTokenService $tokens;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = app(QrTokenService::class);
        $this->secret = $this->tokens->generateSecret();
    }

    public function test_a_freshly_issued_token_verifies(): void
    {
        $raw = $this->tokens->issue('BTEST12345', $this->secret);
        $token = $this->tokens->parse($raw);

        $this->tokens->verify($token, $this->secret);

        $this->assertSame('BTEST12345', $token->publicId);
    }

    public function test_a_token_signed_with_a_different_secret_is_rejected(): void
    {
        $raw = $this->tokens->issue('BTEST12345', $this->secret);
        $token = $this->tokens->parse($raw);

        $this->expectException(QrValidationException::class);
        $this->expectExceptionMessage(__('errors.qr_invalid_signature'));

        $this->tokens->verify($token, $this->tokens->generateSecret());
    }

    public function test_tampering_with_any_field_invalidates_the_signature(): void
    {
        $raw = $this->tokens->issue('BTEST12345', $this->secret);

        // Swap the bus id, keep the signature: the classic forgery attempt.
        $parts = explode('.', $raw);
        $parts[1] = 'BOTHERBUS9';

        $forged = $this->tokens->parse(implode('.', $parts));

        $this->expectException(QrValidationException::class);
        $this->tokens->verify($forged, $this->secret);
    }

    public function test_a_token_expires_once_its_time_window_has_passed(): void
    {
        $rotation = (int) config('transit.qr.rotation_seconds');
        $issuedAt = now()->timestamp;

        $token = $this->tokens->parse($this->tokens->issue('BTEST12345', $this->secret, $issuedAt));

        // Two rotations plus drift tolerance later, it must no longer verify.
        $later = $issuedAt + $rotation * (2 + (int) config('transit.qr.drift_steps'));

        $this->expectException(QrValidationException::class);
        $this->expectExceptionMessage(__('errors.qr_expired'));

        $this->tokens->verify($token, $this->secret, $later);
    }

    public function test_clock_drift_within_tolerance_is_accepted(): void
    {
        $rotation = (int) config('transit.qr.rotation_seconds');
        $issuedAt = now()->timestamp;

        $token = $this->tokens->parse($this->tokens->issue('BTEST12345', $this->secret, $issuedAt));

        // One step of skew is normal on a phone and must not break boarding.
        $this->tokens->verify($token, $this->secret, $issuedAt + $rotation);

        $this->assertTrue(true);
    }

    public function test_a_nonce_can_only_be_consumed_once(): void
    {
        $token = $this->tokens->parse($this->tokens->issue('BTEST12345', $this->secret));

        $this->tokens->consumeNonce($token);

        $this->expectException(QrValidationException::class);
        $this->expectExceptionMessage(__('errors.qr_replayed'));

        // The replay: the very same token presented a second time.
        $this->tokens->consumeNonce($token);
    }

    public function test_releasing_a_nonce_allows_the_passenger_to_retry(): void
    {
        $token = $this->tokens->parse($this->tokens->issue('BTEST12345', $this->secret));

        $this->tokens->consumeNonce($token);
        $this->tokens->releaseNonce($token);

        // No exception: a failed boarding must not burn the passenger's code.
        $this->tokens->consumeNonce($token);

        $this->assertTrue(true);
    }

    public function test_nonces_are_scoped_so_bus_and_merchant_codes_do_not_collide(): void
    {
        $token = $this->tokens->parse($this->tokens->issue('BTEST12345', $this->secret));

        $this->tokens->consumeNonce($token, 'boarding');
        $this->tokens->consumeNonce($token, 'merchant');

        $this->assertTrue(true);
    }

    public function test_two_tokens_issued_in_the_same_window_differ(): void
    {
        $at = now()->timestamp;

        $first = $this->tokens->issue('BTEST12345', $this->secret, $at);
        $second = $this->tokens->issue('BTEST12345', $this->secret, $at);

        // The random nonce is what makes each scan individually revocable.
        $this->assertNotSame($first, $second);
    }

    /** @dataProvider malformedTokens */
    public function test_malformed_payloads_are_rejected(string $raw): void
    {
        $this->expectException(QrValidationException::class);

        $this->tokens->parse($raw);
    }

    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'too few segments' => ['v1.BTEST12345.123'],
            'too many segments' => ['v1.B.1.n.sig.extra'],
            'non numeric step' => ['v1.BTEST12345.abc.nonce.signature'],
            'non numeric version' => ['vX.BTEST12345.1.nonce.signature'],
        ];
    }

    /**
     * A hostile public id parses fine — it is just a string — and that is the
     * point: it is carried as a bound query parameter and simply matches no
     * bus, rather than reaching the database as SQL.
     */
    public function test_a_hostile_public_id_resolves_to_unknown_code_without_touching_the_schema(): void
    {
        $bus = Bus::factory()->create();
        app(BusQrService::class)->issueFor($bus);

        $hostile = "'; DROP TABLE buses;--";
        $raw = $this->tokens->issue($hostile, $this->secret);

        try {
            app(BusQrService::class)->resolveScan($raw);
            $this->fail('A non-existent public id must be rejected.');
        } catch (QrValidationException $e) {
            $this->assertSame(__('errors.qr_unknown_code'), $e->getMessage());
        }

        // The table is still there, with its row.
        $this->assertSame(1, Bus::count());
    }

    public function test_a_deep_link_url_is_accepted_as_a_token(): void
    {
        $raw = $this->tokens->issue('BTEST12345', $this->secret);

        // A generic camera app opens the URL form; both must resolve.
        $token = $this->tokens->parse('https://example.test/q/'.$raw);

        $this->tokens->verify($token, $this->secret);

        $this->assertSame('BTEST12345', $token->publicId);
    }
}
