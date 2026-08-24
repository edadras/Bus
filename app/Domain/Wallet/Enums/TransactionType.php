<?php

namespace App\Domain\Wallet\Enums;

use App\Support\Concerns\HasLabel;

enum TransactionType: string
{
    use HasLabel;

    case Topup = 'topup';
    case FarePayment = 'fare_payment';
    case MerchantPayment = 'merchant_payment';
    case TaxiFare = 'taxi_fare';
    case SchoolFee = 'school_fee';
    case Refund = 'refund';
    case Settlement = 'settlement';
    case Commission = 'commission';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';

    public function color(): string
    {
        return match ($this) {
            self::Topup, self::Refund => 'success',
            self::FarePayment, self::MerchantPayment, self::TaxiFare, self::SchoolFee => 'info',
            self::Reversal, self::Adjustment => 'warning',
            default => 'neutral',
        };
    }
}
