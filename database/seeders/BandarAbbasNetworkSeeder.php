<?php

namespace Database\Seeders;

use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Models\City;
use App\Domain\Network\Services\NetworkImporter;
use Illuminate\Database\Seeder;

/**
 * Loads the Bandar Abbas network through the same import pipeline an operator
 * would use, rather than through bespoke seeder code. That way the importer is
 * exercised on every fresh install and the seed data has no privileged path.
 *
 * The bundled files are SAMPLE data: real place names, approximate positions,
 * invented line orderings. They are imported with `sample` provenance so every
 * client labels them as unofficial. See database/data/bandar-abbas/README.md.
 */
class BandarAbbasNetworkSeeder extends Seeder
{
    public function run(): void
    {
        $city = City::where('slug', 'bandar-abbas')->first();

        if ($city === null) {
            $this->command?->warn('Bandar Abbas city missing; run CitySeeder first.');

            return;
        }

        $importer = app(NetworkImporter::class);
        $base = database_path('data/bandar-abbas');

        $steps = [
            'stops' => fn () => $importer->importStops($city, "$base/stops.csv", NetworkProvenance::Sample),
            'lines' => fn () => $importer->importLines($city, "$base/lines.csv", NetworkProvenance::Sample),
            'routes' => fn () => $importer->importRoutes($city, "$base/routes.csv", NetworkProvenance::Sample),
            'geometry' => fn () => $importer->importGeometry($city, "$base/shapes.geojson", NetworkProvenance::Sample),
        ];

        foreach ($steps as $label => $step) {
            $batch = $step();

            $this->command?->info(sprintf(
                '  %-9s created %d, updated %d, skipped %d',
                $label, $batch->created_rows, $batch->updated_rows, $batch->skipped_rows,
            ));

            foreach (array_slice($batch->errors ?? [], 0, 5) as $error) {
                $this->command?->warn("    line {$error['line']}: {$error['message']}");
            }
        }

        $this->command?->warn('  Network loaded as SAMPLE data — not the official Bandar Abbas network.');
    }
}
