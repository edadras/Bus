<?php

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Gateways\ManualGateway;
use App\Domain\Payment\Gateways\SandboxGateway;
use App\Support\Exceptions\DomainException;
use Illuminate\Contracts\Container\Container;

/** Resolves a gateway driver by name, honouring the enabled flag in config. */
class PaymentGatewayManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    private array $drivers = [
        'sandbox' => SandboxGateway::class,
        'manual' => ManualGateway::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function extend(string $name, string $class): void
    {
        $this->drivers[$name] = $class;
    }

    public function driver(?string $name = null): PaymentGateway
    {
        $name ??= (string) config('wallet.gateway');

        $config = config("wallet.gateways.$name");

        if ($config === null || ($config['enabled'] ?? false) !== true) {
            throw DomainException::make('gateway_unavailable', 422, ['gateway' => $name]);
        }

        $class = $this->drivers[$config['driver'] ?? $name] ?? null;

        if ($class === null) {
            throw DomainException::make('gateway_unavailable', 422, ['gateway' => $name]);
        }

        return $this->container->make($class);
    }

    /** @return array<int, string> gateway names currently usable */
    public function available(): array
    {
        return array_keys(array_filter(
            (array) config('wallet.gateways'),
            fn (array $config) => ($config['enabled'] ?? false) === true,
        ));
    }
}
