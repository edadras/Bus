<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Contracts\SmsGateway;
use App\Domain\Notifications\Gateways\KavenegarSmsGateway;
use App\Domain\Notifications\Gateways\LogSmsGateway;
use App\Domain\Notifications\Gateways\SmsIrGateway;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/** Resolves an SMS driver by name, exactly as the payment manager does. */
class SmsGatewayManager
{
    /** @var array<string, class-string<SmsGateway>> */
    private array $drivers = [
        'log' => LogSmsGateway::class,
        'kavenegar' => KavenegarSmsGateway::class,
        'smsir' => SmsIrGateway::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function extend(string $name, string $class): void
    {
        $this->drivers[$name] = $class;
    }

    public function driver(?string $name = null): SmsGateway
    {
        $name ??= (string) config('sms.default', 'log');

        $driver = (string) config("sms.gateways.$name.driver", $name);

        $class = $this->drivers[$driver] ?? null;

        if ($class === null) {
            // Falling back beats throwing: an unknown driver name in config
            // must not take sign-in down, and the log says exactly what is
            // wrong.
            Log::channel('sms')->error('Unknown SMS driver; falling back to log.', [
                'configured' => $name,
            ]);

            $class = LogSmsGateway::class;
        }

        return $this->container->make($class);
    }

    /** The pre-approved template for verification codes, if this driver uses one. */
    public function otpTemplate(?string $name = null): ?string
    {
        $name ??= (string) config('sms.default', 'log');

        $template = config("sms.gateways.$name.otp_template");

        return blank($template) ? null : (string) $template;
    }
}
