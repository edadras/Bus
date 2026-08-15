<?php

namespace App\Console\Commands;

use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Models\City;
use App\Domain\Network\Services\NetworkImporter;
use Illuminate\Console\Command;

class ImportNetworkCommand extends Command
{
    protected $signature = 'transit:import
        {entity : stops|lines|routes|geometry}
        {path : Path to the CSV or GeoJSON file}
        {--city= : City slug (defaults to the configured default city)}
        {--provenance=sample : official|community|sample}';

    protected $description = 'Import network data (stops, lines, routes, geometry) from CSV or GeoJSON';

    public function handle(NetworkImporter $importer): int
    {
        $city = City::where('slug', $this->option('city') ?? config('transit.default_city'))->first();

        if ($city === null) {
            $this->error('City not found.');

            return self::FAILURE;
        }

        $path = $this->argument('path');

        if (! is_readable($path)) {
            $this->error("File not readable: $path");

            return self::FAILURE;
        }

        $provenance = NetworkProvenance::tryFrom($this->option('provenance'));

        if ($provenance === null) {
            $this->error('Provenance must be one of: official, community, sample.');

            return self::FAILURE;
        }

        if ($provenance !== NetworkProvenance::Official) {
            $this->warn("Importing as [{$provenance->value}] data — it will be labelled as unofficial in every client.");
        }

        $batch = match ($this->argument('entity')) {
            'stops' => $importer->importStops($city, $path, $provenance),
            'lines' => $importer->importLines($city, $path, $provenance),
            'routes' => $importer->importRoutes($city, $path, $provenance),
            'geometry' => $importer->importGeometry($city, $path, $provenance),
            default => null,
        };

        if ($batch === null) {
            $this->error('Entity must be one of: stops, lines, routes, geometry.');

            return self::FAILURE;
        }

        $this->table(
            ['Total', 'Created', 'Updated', 'Skipped'],
            [[$batch->total_rows, $batch->created_rows, $batch->updated_rows, $batch->skipped_rows]],
        );

        foreach (array_slice($batch->errors ?? [], 0, 15) as $error) {
            $this->warn("  line {$error['line']}: {$error['message']}");
        }

        if ($batch->skipped_rows > 0) {
            $this->warn("{$batch->skipped_rows} row(s) skipped. See import batch #{$batch->id} for the full list.");
        }

        $this->info("Import batch #{$batch->id} {$batch->status}.");

        return self::SUCCESS;
    }
}
