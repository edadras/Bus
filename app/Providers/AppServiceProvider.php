<?php

namespace App\Providers;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\User;
use App\Domain\Mapping\Contracts\MapProvider;
use App\Domain\Mapping\Providers\TileMapProvider;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Merchant\Models\MerchantTransaction;
use App\Domain\Merchant\Models\Settlement;
use App\Domain\Operations\Models\Trip;
use App\Domain\Ridership\Models\PassengerTrip;
use App\Domain\Support\Models\Complaint;
use App\Domain\Wallet\Models\Payment;
use App\Domain\Wallet\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
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
        // Models live under App\Domain\<Module>\Models, so the default
        // convention would look for Database\Factories\Domain\...\XFactory.
        // Factories are kept flat instead, one per model class name.
        Factory::guessFactoryNamesUsing(
            static fn (string $model) => 'Database\\Factories\\'.class_basename($model).'Factory',
        );

        // Fail loudly in development when a relation is used without eager
        // loading, rather than shipping an N+1 to production unnoticed.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Stable morph aliases: the polymorphic columns store these strings,
        // so class names can be refactored without a data migration.
        Relation::enforceMorphMap([
            'user' => User::class,
            'trip' => Trip::class,
            'passenger_trip' => PassengerTrip::class,
            'merchant_transaction' => MerchantTransaction::class,
            'settlement' => Settlement::class,
            'payment' => Payment::class,
            'wallet_transaction' => WalletTransaction::class,
            'complaint' => Complaint::class,
            'bus' => Bus::class,
            'driver' => Driver::class,
            'merchant' => Merchant::class,
        ]);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
