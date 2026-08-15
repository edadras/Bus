<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The browser-facing surfaces render. These are shallow by design — behaviour
 * lives in the API tests — but they catch the class of failure a template
 * refactor produces: a page that returns 200 with its body missing.
 */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeCity(['name' => 'بندرعباس', 'province' => 'هرمزگان']);
    }

    public function test_the_landing_page_renders_its_content(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('حمل‌ونقل هوشمند', escape: false)
            // The layout chain must actually emit the body, not just the head.
            ->assertSee('<main', escape: false)
            ->assertSee('پرسش‌های متداول', escape: false);
    }

    public function test_the_landing_page_labels_sample_network_data(): void
    {
        // Nothing may present unofficial geometry as the published network.
        $this->get('/')->assertOk()->assertSee('نمونه', escape: false);
    }

    public function test_the_public_web_viewer_renders(): void
    {
        $this->get('/app/passenger')->assertOk()->assertSee('نمای عمومی', escape: false);
    }

    public function test_the_driver_and_merchant_pages_point_at_the_native_apps(): void
    {
        $this->get('/app/driver')->assertOk()->assertSee('Flutter', escape: false);
        $this->get('/app/merchant')->assertOk()->assertSee('Flutter', escape: false);
    }

    public function test_each_app_serves_its_own_installable_manifest(): void
    {
        foreach (['passenger', 'driver', 'merchant'] as $app) {
            $this->get("/manifest/$app.webmanifest")
                ->assertOk()
                ->assertJsonPath('start_url', "/app/$app")
                ->assertJsonPath('dir', 'rtl');
        }

        $this->get('/manifest/unknown.webmanifest')->assertNotFound();
    }

    public function test_the_service_worker_is_served_with_root_scope(): void
    {
        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/')
            // A stale wallet balance is worse than none, so the API is never
            // served from cache.
            ->assertSee('Never cache the API', escape: false);
    }

    public function test_the_admin_shell_and_login_render(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('پنل مدیریت', escape: false);
        $this->get('/admin')->assertOk()->assertSee('adminShell', escape: false);
    }

    public function test_the_offline_page_renders(): void
    {
        $this->get('/offline')->assertOk()->assertSee('اتصال اینترنت', escape: false);
    }

    public function test_pages_declare_persian_and_rtl(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('lang="fa"', escape: false)
            ->assertSee('dir="rtl"', escape: false);
    }
}
