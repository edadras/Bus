<?php

namespace App\Providers;

use App\Domain\Fleet\Services\BusQrService;
use App\Domain\Fleet\Services\QrTokenService;
use App\Domain\Identity\Services\AuditLogger;
use App\Domain\Merchant\Services\MerchantTerminalQrService;
use App\Domain\Operations\Services\EtaEngine;
use App\Domain\Operations\Services\LiveStateStore;
use App\Domain\Operations\Services\RouteMatcher;
use App\Domain\Operations\Services\TripEventRecorder;
use App\Domain\Payment\Services\PaymentGatewayManager;
use App\Domain\Wallet\Services\FareEngine;
use App\Domain\Wallet\Services\LedgerService;
use App\Domain\Wallet\Services\SystemAccountRegistry;
use App\Domain\Wallet\Services\WalletService;
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
        ] as $service) {
            $this->app->singleton($service);
        }
    }
}
