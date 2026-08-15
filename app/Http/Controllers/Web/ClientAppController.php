<?php

namespace App\Http\Controllers\Web;

use App\Domain\Network\Models\City;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Serves the three installable client apps.
 *
 * Each is a single Blade shell that boots an Alpine application against the
 * same public API a native app would use — so the PWA is never a second-class
 * client with privileged access.
 */
class ClientAppController extends Controller
{
    private const APPS = [
        'passenger' => [
            'title' => 'اپلیکیشن مسافر',
            'short' => 'همسفر',
            'color' => '#12b76a',
            'description' => 'ردیابی زنده اتوبوس، زمان رسیدن و کیف پول شهری',
        ],
        'driver' => [
            'title' => 'اپلیکیشن راننده',
            'short' => 'همسفر راننده',
            'color' => '#0ea5e9',
            'description' => 'مدیریت شیفت، مسیر و مسافران',
        ],
        'merchant' => [
            'title' => 'اپلیکیشن پذیرنده',
            'short' => 'همسفر پذیرنده',
            'color' => '#f59e0b',
            'description' => 'دریافت پرداخت، گزارش فروش و تسویه',
        ],
    ];

    public function passenger(): View
    {
        return $this->render('passenger');
    }

    public function driver(): View
    {
        return $this->render('driver');
    }

    public function merchant(): View
    {
        return $this->render('merchant');
    }

    /**
     * Deep link target for a scanned bus sticker: opens the passenger app with
     * the code prefilled, so a generic camera app is a usable entry point.
     */
    public function scanEntry(string $token): View
    {
        return $this->render('passenger', ['prefilledToken' => $token]);
    }

    /** Per-app web manifest, so each installs with its own name and icon. */
    public function manifest(string $app): JsonResponse
    {
        abort_unless(isset(self::APPS[$app]), 404);

        $meta = self::APPS[$app];

        return response()->json([
            'id' => "/app/$app",
            'name' => $meta['title'].' — '.__('common.app_name'),
            'short_name' => $meta['short'],
            'description' => $meta['description'],
            'start_url' => "/app/$app",
            'scope' => "/app/$app",
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#05090b',
            'theme_color' => '#05090b',
            'lang' => 'fa',
            'dir' => 'rtl',
            'categories' => ['travel', 'navigation', 'finance'],
            'icons' => [
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ])->header('Content-Type', 'application/manifest+json');
    }

    /**
     * Service worker. Served from the site root scope with a no-store header so
     * a stale worker cannot pin an old build indefinitely.
     */
    public function serviceWorker(): Response
    {
        return response()
            ->view('pwa.service-worker', [], 200)
            ->header('Content-Type', 'application/javascript')
            ->header('Service-Worker-Allowed', '/')
            ->header('Cache-Control', 'no-store');
    }

    public function offline(): View
    {
        return view('pwa.offline');
    }

    private function render(string $app, array $extra = []): View
    {
        $city = City::where('slug', config('transit.default_city'))->firstOrFail();

        return view("apps.$app", array_merge([
            'app' => $app,
            'meta' => self::APPS[$app],
            'city' => $city,
        ], $extra));
    }
}
