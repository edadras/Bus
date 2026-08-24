<?php

namespace App\Domain\Taxi\DTO;

use App\Domain\Taxi\Enums\TaxiServiceType;
use App\Domain\Taxi\Models\TaxiTariff;
use App\Support\Money;

/**
 * A priced taxi fare and the arithmetic that produced it.
 *
 * The breakdown is not decoration. A metered fare is the one charge on the
 * platform the passenger cannot check in advance, so every component that went
 * into it is carried through to the receipt and stored on the ride.
 */
final readonly class TaxiFareQuote
{
    public function __construct(
        public int $amount,
        public TaxiServiceType $serviceType,
        public ?TaxiTariff $tariff = null,
        public array $breakdown = [],
    ) {}

    public function formatted(): string
    {
        return Money::format($this->amount);
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'formatted' => $this->formatted(),
            'currency' => Money::currency(),
            'service_type' => $this->serviceType->value,
            'service_type_label' => $this->serviceType->label(),
            'tariff' => $this->tariff === null ? null : [
                'id' => $this->tariff->id,
                'name' => $this->tariff->name,
            ],
            'breakdown' => $this->breakdown,
        ];
    }
}
