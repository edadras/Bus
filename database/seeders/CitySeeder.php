<?php

namespace Database\Seeders;

use App\Domain\Network\Models\City;
use App\Domain\Network\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * Cities. Bandar Abbas launches first; the others are pre-registered but not
 * launched, which exercises the multi-city paths without exposing empty
 * networks to passengers.
 */
class CitySeeder extends Seeder
{
    public function run(): void
    {
        $bandarAbbas = City::updateOrCreate(
            ['slug' => 'bandar-abbas'],
            [
                'name' => 'بندرعباس',
                'name_en' => 'Bandar Abbas',
                'country_code' => 'IR',
                'province' => 'هرمزگان',
                'timezone' => 'Asia/Tehran',
                'currency' => 'IRR',
                'locale' => 'fa',
                'center_lat' => 27.1832,
                'center_lng' => 56.2666,
                'default_zoom' => 13,
                // Envelope around the built-up area; pings outside are rejected.
                'bbox_min_lat' => 27.0500,
                'bbox_min_lng' => 56.0800,
                'bbox_max_lat' => 27.3200,
                'bbox_max_lng' => 56.5200,
                'is_active' => true,
                'is_launched' => true,
            ],
        );

        foreach ([
            ['code' => 'CENTRAL', 'name' => 'مرکز شهر'],
            ['code' => 'EAST', 'name' => 'شرق'],
            ['code' => 'WEST', 'name' => 'غرب'],
            ['code' => 'PORT', 'name' => 'محدوده بندر'],
        ] as $zone) {
            Zone::updateOrCreate(
                ['city_id' => $bandarAbbas->id, 'code' => $zone['code']],
                ['name' => $zone['name'], 'is_active' => true],
            );
        }

        // Registered for the multi-city architecture, deliberately not launched.
        foreach ([
            ['slug' => 'tehran', 'name' => 'تهران', 'name_en' => 'Tehran', 'lat' => 35.6892, 'lng' => 51.3890, 'province' => 'تهران'],
            ['slug' => 'shiraz', 'name' => 'شیراز', 'name_en' => 'Shiraz', 'lat' => 29.5918, 'lng' => 52.5837, 'province' => 'فارس'],
            ['slug' => 'isfahan', 'name' => 'اصفهان', 'name_en' => 'Isfahan', 'lat' => 32.6546, 'lng' => 51.6680, 'province' => 'اصفهان'],
            ['slug' => 'mashhad', 'name' => 'مشهد', 'name_en' => 'Mashhad', 'lat' => 36.2605, 'lng' => 59.6168, 'province' => 'خراسان رضوی'],
        ] as $city) {
            City::updateOrCreate(
                ['slug' => $city['slug']],
                [
                    'name' => $city['name'],
                    'name_en' => $city['name_en'],
                    'province' => $city['province'],
                    'center_lat' => $city['lat'],
                    'center_lng' => $city['lng'],
                    'default_zoom' => 12,
                    'is_active' => true,
                    'is_launched' => false,
                ],
            );
        }
    }
}
