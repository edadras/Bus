<?php

namespace App\Providers;

use App\Domain\Fleet\Services\BusQrService;
use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Identity\Services\AuditLogger;
use App\Domain\Merchant\Services\MerchantTerminalQrService;
use App\Domain\Notifications\Channels\WebPushChannel;
use App\Domain\Notifications\Services\WebPushSender;
use App\Domain\Operations\Services\EtaEngine;
use App\Domain\Operations\Services\LiveStateStore;
use App\Domain\Operations\Services\RouteMatcher;
use App\Domain\Operations\Services\TripEventRecorder;
use App\Domain\Payment\Services\PaymentGatewayManager;
use App\Domain\Wallet\Services\FareEngine;
use App\Domain\Wallet\Services\LedgerService;
use App\Domain\Wallet\Services\SystemAccountRegistry;
use App\Domain\Wallet\Services\WalletService;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;

/**
 * Domain services that must behave as singletons — either because they hold a
 * request-scoped cache (system accounts, route geometry) or because sharing one
 * instance keeps their internal invariants intact.
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([
            SystemAccountRegistry::class,
            LedgerService::class,
            WalletService::class,
            FareEngine::class,
            QrTokenService::class,
            BusQrService::class,
            MerchantTerminalQrService::class,
            RouteMatcher::class,
            LiveStateStore::class,
            EtaEngine::class,
            TripEventRecorder::class,
            PaymentGatewayManager::class,
            AuditLogger::class,
            WebPushSender::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        // Adds `webpush` to the channels a notification may list in via().
        // Notifications without a toWebPush() method are skipped by the
        // channel itself, so this is safe to register unconditionally.
        Notification::resolved(function (ChannelManager $manager): void {
            $manager->extend('webpush', fn ($app) => $app->make(WebPushChannel::class));
        });
    }
}
