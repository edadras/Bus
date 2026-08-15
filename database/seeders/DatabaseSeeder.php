<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Order matters: RBAC and cities are structural and must exist before any
     * record that references a role or a city. The demo data is skipped in
     * production so a real deployment never gets seeded accounts.
     */
    public function run(): void
    {
        $this->call([
            RbacSeeder::class,
            CitySeeder::class,
            FareRuleSeeder::class,
            BandarAbbasNetworkSeeder::class,
        ]);

        if (app()->environment('production')) {
            $this->command?->warn('Production environment: demo data skipped.');

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
