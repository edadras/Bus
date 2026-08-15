<?php

namespace App\Providers;

use App\Domain\Identity\Models\User;
use App\Domain\Mapping\Contracts\MapProvider;
use App\Domain\Mapping\Providers\TileMapProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MapProvider::class, fn () => new TileMapProvider(
            (string) config('transit.map.provider', 'osm'),
        ));
    }

    public function boot(): void
    {
        // Fail loudly in development when a relation is used without eager
        // loading, rather than shipping an N+1 to production unnoticed.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Stable morph aliases: the polymorphic columns store these strings,
        // so class names can be refactored without a data migration.
        Relation::enforceMorphMap([
            'user' => User::class,
            'trip' => \App\Domain\Operations\Models\Trip::class,
            'passenger_trip' => \App\Domain\Ridership\Models\PassengerTrip::class,
            'merchant_transaction' => \App\Domain\Merchant\Models\MerchantTransaction::class,
            'settlement' => \App\Domain\Merchant\Models\Settlement::class,
            'payment' => \App\Domain\Wallet\Models\Payment::class,
            'wallet_transaction' => \App\Domain\Wallet\Models\WalletTransaction::class,
            'complaint' => \App\Domain\Support\Models\Complaint::class,
            'bus' => \App\Domain\Fleet\Models\Bus::class,
            'driver' => \App\Domain\Fleet\Models\Driver::class,
            'merchant' => \App\Domain\Merchant\Models\Merchant::class,
        ]);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
