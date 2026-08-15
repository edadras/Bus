<?php

namespace Tests\Feature\Web;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * The product is Persian-first but declares English as a supported locale, and
 * a declared locale that has no translations does not fall back gracefully —
 * it renders raw dotted keys at the user. These tests exist because that is
 * exactly what happened: `?lang=en` returned `common.app_name` everywhere.
 */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function keysOf(string $locale): array
    {
        $keys = [];

        foreach (glob(lang_path($locale.'/*.php')) as $file) {
            $group = basename($file, '.php');

            foreach (Arr::dot(require $file) as $key => $value) {
                $keys[] = $group.'.'.$key;
            }
        }

        sort($keys);

        return $keys;
    }

    public function test_persian_is_the_default_locale(): void
    {
        $this->assertSame('fa', config('app.locale'));
    }

    public function test_every_declared_locale_has_the_same_keys(): void
    {
        $fa = $this->keysOf('fa');
        $en = $this->keysOf('en');

        $this->assertNotEmpty($fa);

        $this->assertSame([], array_values(array_diff($fa, $en)), 'Keys missing from lang/en.');
        $this->assertSame([], array_values(array_diff($en, $fa)), 'Keys missing from lang/fa.');
    }

    public function test_no_translation_resolves_to_its_own_key(): void
    {
        foreach (['fa', 'en'] as $locale) {
            foreach ($this->keysOf($locale) as $key) {
                $this->assertNotSame(
                    $key,
                    Lang::get($key, [], $locale),
                    "[$key] has no translation in [$locale] and would render as a raw key.",
                );
            }
        }
    }

    public function test_every_domain_error_code_has_a_message(): void
    {
        // A DomainException renders __('errors.'.$code); a code with no entry
        // ships a dotted key straight to the client.
        $codes = [];

        foreach ((new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path()),
        )) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all(
                "/DomainException::make\(\s*'([a-z_]+)'/",
                (string) file_get_contents($file->getPathname()),
                $matches,
            );

            $codes = array_merge($codes, $matches[1]);
        }

        $this->assertNotEmpty($codes);

        foreach (array_unique($codes) as $code) {
            // Codes assembled by concatenation (account_.$status) are covered
            // by their concrete forms elsewhere in this list.
            if (str_ends_with($code, '_')) {
                continue;
            }

            $this->assertTrue(
                Lang::has('errors.'.$code, 'fa') && Lang::has('errors.'.$code, 'en'),
                "Error code [$code] has no message in one of the locales.",
            );
        }
    }

    public function test_an_explicit_language_request_wins_over_everything(): void
    {
        $this->makeCity();

        $this->getJson('/api/v1/summary?lang=en')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        $this->getJson('/api/v1/summary')
            ->assertOk()
            ->assertHeader('Content-Language', 'fa');
    }

    public function test_a_phone_set_to_english_does_not_flip_a_persian_riders_interface(): void
    {
        $this->makeCity();

        // Accept-Language is deliberately not consulted.
        $this->getJson('/api/v1/summary', ['Accept-Language' => 'en-GB,en;q=0.9'])
            ->assertOk()
            ->assertHeader('Content-Language', 'fa');
    }

    public function test_a_users_saved_preference_is_honoured(): void
    {
        $city = $this->makeCity();
        $user = User::factory()->create(['city_id' => $city->id, 'locale' => 'en']);

        $this->actingAsPassenger($user)
            ->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');
    }

    public function test_error_messages_are_returned_in_the_requested_locale(): void
    {
        $this->makeCity();

        $response = $this->postJson('/api/v1/auth/otp/verify?lang=en', [
            'mobile' => '09120000000',
            'code' => '000000',
        ])->assertStatus(422);

        $this->assertStringNotContainsString('errors.', (string) $response->json('error.message'));
        $this->assertMatchesRegularExpression('/[A-Za-z]/', (string) $response->json('error.message'));
    }
}
