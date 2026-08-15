<?php

namespace App\Domain\Merchant\Enums;

use App\Support\Concerns\HasLabel;

enum MerchantType: string
{
    use HasLabel;

    case SwimmingPool = 'swimming_pool';
    case Gym = 'gym';
    case SportsCenter = 'sports_center';
    case Entertainment = 'entertainment';
    case Restaurant = 'restaurant';
    case Store = 'store';
    case Cinema = 'cinema';
    case Parking = 'parking';
    case Other = 'other';
}
