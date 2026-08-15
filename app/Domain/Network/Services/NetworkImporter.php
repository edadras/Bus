<?php

namespace App\Domain\Network\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Enums\RouteDirection;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusRoute;
use App\Domain\Network\Models\BusStop;
use App\Domain\Network\Models\City;
use App\Domain\Network\Models\ImportBatch;
use App\Domain\Network\Models\Zone;
use App\Domain\Operations\Services\RouteMatcher;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Bulk import of network data from CSV and GeoJSON.
 *
 * Two things this deliberately gets right:
 *
 *  - Provenance travels with the data. Anything imported without an explicit
 *    `official` marking stays labelled as sample/community all the way to the
 *    passenger's screen, so demo geometry can never masquerade as the
 *    published network.
 *  - Errors are collected, not thrown. One malformed row in a 2,000-row export
 *    should not abandon the other 1,999; every skipped row is recorded on the
 *    batch with its line number so the operator can fix and re-run.
 */
class NetworkImporter
{
    public function __construct(private readonly RouteMatcher $matcher) {}

    public function importStops(
        City $city,
        string $path,
        NetworkProvenance $provenance = NetworkProvenance::Sample,
        ?User $actor = null,
    ): ImportBatch {
        $batch = $this->startBatch($city, 'stops', 'csv', $path, $provenance, $actor);
        $zones = Zone::where('city_id', $city->id)->pluck('id', 'code');

        foreach ($this->readCsv($path, $batch) as $lineNumber => $row) {
            try {
                $this->requireColumns($row, ['code', 'name', 'lat', 'lng']);

                $stop = BusStop::updateOrCreate(
                    ['city_id' => $city->id, 'code' => trim($row['code'])],
                    [
                        'name' => trim($row['name']),
                        'name_en' => $this->nullable($row['name_en'] ?? null),
                        'lat' => (float) $row['lat'],
                        'lng' => (float) $row['lng'],
                        'address' => $this->nullable($row['address'] ?? null),
                        'zone_id' => $zones[$row['zone_code'] ?? null] ?? null,
                        'is_terminal' => (bool) ($row['is_terminal'] ?? false),
                        'geofence_radius' => (int) ($row['geofence_radius'] ?? 60),
                        'is_active' => true,
                        'provenance' => $provenance,
                        'source_ref' => basename($path),
                    ],
                );

                $stop->wasRecentlyCreated ? $batch->created_rows++ : $batch->updated_rows++;
            } catch (\Throwable $e) {
                $this->recordError($batch, $lineNumber, $e->getMessage());
            }
        }

        return $this->finishBatch($batch);
    }

    public function importLines(
        City $city,
        string $path,
        NetworkProvenance $provenance = NetworkProvenance::Sample,
        ?User $actor = null,
    ): ImportBatch {
        $batch = $this->startBatch($city, 'lines', 'csv', $path, $provenance, $actor);

        foreach ($this->readCsv($path, $batch) as $lineNumber => $row) {
            try {
                $this->requireColumns($row, ['code', 'name']);

                $line = BusLine::updateOrCreate(
                    ['city_id' => $city->id, 'code' => trim($row['code'])],
                    [
                        'name' => trim($row['name']),
                        'name_en' => $this->nullable($row['name_en'] ?? null),
                        'color' => $this->nullable($row['color'] ?? null) ?? '#16a34a',
                        'origin_label' => $this->nullable($row['origin_label'] ?? null),
                        'destination_label' => $this->nullable($row['destination_label'] ?? null),
                        'typical_duration_minutes' => $this->intOrNull($row['typical_duration_minutes'] ?? null),
                        'headway_minutes' => $this->intOrNull($row['headway_minutes'] ?? null),
                        'is_active' => true,
                        'provenance' => $provenance,
                    ],
                );

                $line->wasRecentlyCreated ? $batch->created_rows++ : $batch->updated_rows++;
            } catch (\Throwable $e) {
                $this->recordError($batch, $lineNumber, $e->getMessage());
            }
        }

        return $this->finishBatch($batch);
    }

    /**
     * Import route stop sequences. Rows are grouped by (line, direction) and
     * each group replaces that route's sequence wholesale — a partial update
     * would leave a route with a broken ordering, which silently corrupts ETA.
     */
    public function importRoutes(
        City $city,
        string $path,
        NetworkProvenance $provenance = NetworkProvenance::Sample,
        ?User $actor = null,
    ): ImportBatch {
        $batch = $this->startBatch($city, 'routes', 'csv', $path, $provenance, $actor);

        $lines = BusLine::where('city_id', $city->id)->pluck('id', 'code');
        $stops = BusStop::where('city_id', $city->id)->pluck('id', 'code');

        $grouped = [];

        foreach ($this->readCsv($path, $batch) as $lineNumber => $row) {
            try {
                $this->requireColumns($row, ['line_code', 'direction', 'sequence', 'stop_code']);

                $lineId = $lines[trim($row['line_code'])] ?? null;
                $stopId = $stops[trim($row['stop_code'])] ?? null;

                if ($lineId === null) {
                    throw new \RuntimeException("Unknown line code [{$row['line_code']}].");
                }

                if ($stopId === null) {
                    throw new \RuntimeException("Unknown stop code [{$row['stop_code']}].");
                }

                $key = $lineId.':'.trim($row['direction']);

                $grouped[$key][] = [
                    'line_id' => $lineId,
                    'direction' => trim($row['direction']),
                    'sequence' => (int) $row['sequence'],
                    'stop_id' => $stopId,
                    'dwell_seconds' => (int) ($row['dwell_seconds'] ?? 20),
                ];
            } catch (\Throwable $e) {
                $this->recordError($batch, $lineNumber, $e->getMessage());
            }
        }

        foreach ($grouped as $rows) {
            try {
                DB::transaction(function () use ($rows, $provenance, $batch): void {
                    usort($rows, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

                    $first = $rows[0];
                    $direction = RouteDirection::from($first['direction']);

                    $route = BusRoute::firstOrNew([
                        'bus_line_id' => $first['line_id'],
                        'direction' => $direction->value,
                    ]);

                    $route->fill([
                        'name' => $route->name ?? ($direction->label()),
                        'origin_stop_id' => $first['stop_id'],
                        'destination_stop_id' => end($rows)['stop_id'],
                        'is_active' => true,
                        'is_default' => $direction === RouteDirection::Outbound,
                        'provenance' => $provenance,
                    ])->save();

                    $route->routeStops()->delete();

                    foreach ($rows as $index => $row) {
                        $route->routeStops()->create([
                            'bus_stop_id' => $row['stop_id'],
                            'sequence' => $index + 1,
                            'dwell_seconds' => $row['dwell_seconds'],
                        ]);
                    }

                    // Offsets must be derived now; every downstream calculation
                    // ("next stop", distance, ETA) reads them, not the geometry.
                    $this->matcher->recalculateStopOffsets($route);

                    $route->wasRecentlyCreated ? $batch->created_rows++ : $batch->updated_rows++;
                });
            } catch (\Throwable $e) {
                $this->recordError($batch, 0, $e->getMessage());
            }
        }

        return $this->finishBatch($batch);
    }

    /**
     * Attach surveyed shapes from a GeoJSON FeatureCollection. GeoJSON stores
     * coordinates as [lng, lat]; getting that order wrong puts Bandar Abbas in
     * the Indian Ocean, so the swap is done once, here.
     */
    public function importGeometry(
        City $city,
        string $path,
        NetworkProvenance $provenance = NetworkProvenance::Sample,
        ?User $actor = null,
    ): ImportBatch {
        $batch = $this->startBatch($city, 'geometry', 'geojson', $path, $provenance, $actor);

        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $features = $json['features'] ?? [];
        $batch->total_rows = count($features);

        $lines = BusLine::where('city_id', $city->id)->pluck('id', 'code');

        foreach ($features as $index => $feature) {
            try {
                $lineCode = $feature['properties']['line_code'] ?? null;
                $direction = $feature['properties']['direction'] ?? 'outbound';

                if ($lineCode === null || ! isset($lines[$lineCode])) {
                    throw new \RuntimeException("Unknown or missing line_code in feature #$index.");
                }

                if (($feature['geometry']['type'] ?? null) !== 'LineString') {
                    throw new \RuntimeException("Feature #$index is not a LineString.");
                }

                $route = BusRoute::where('bus_line_id', $lines[$lineCode])
                    ->where('direction', $direction)
                    ->first();

                if ($route === null) {
                    throw new \RuntimeException("No route for line $lineCode direction $direction.");
                }

                $geometry = array_map(
                    static fn (array $pair) => ['lat' => (float) $pair[1], 'lng' => (float) $pair[0]],
                    $feature['geometry']['coordinates'],
                );

                $route->forceFill(['geometry' => $geometry, 'provenance' => $provenance])->save();

                // New geometry means every stop offset is now wrong.
                $this->matcher->recalculateStopOffsets($route->fresh());

                $batch->updated_rows++;
            } catch (\Throwable $e) {
                $this->recordError($batch, $index, $e->getMessage());
            }
        }

        return $this->finishBatch($batch);
    }

    /** @return \Generator<int, array<string, string>> */
    private function readCsv(string $path, ImportBatch $batch): \Generator
    {
        if (! is_readable($path)) {
            throw DomainException::make('import_file_unreadable', 422, ['path' => $path]);
        }

        $handle = fopen($path, 'rb');

        // Strip a UTF-8 BOM: Excel writes one and it corrupts the first header.
        $first = fgets($handle);
        if ($first !== false && str_starts_with($first, "\xEF\xBB\xBF")) {
            $first = substr($first, 3);
        }
        rewind($handle);
        if ($first !== false && str_starts_with((string) fgets($handle), "\xEF\xBB\xBF")) {
            fseek($handle, 3);
        } else {
            rewind($handle);
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            throw DomainException::make('import_file_empty', 422);
        }

        $header = array_map(static fn ($column) => trim((string) $column), $header);
        $lineNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            // Skip blank trailing lines rather than reporting them as errors.
            if ($row === [null] || $row === [] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }

            $batch->total_rows++;

            $padded = array_pad($row, count($header), null);

            yield $lineNumber => array_combine($header, array_slice($padded, 0, count($header)));
        }

        fclose($handle);
    }

    private function requireColumns(array $row, array $columns): void
    {
        foreach ($columns as $column) {
            if (! isset($row[$column]) || trim((string) $row[$column]) === '') {
                throw new \RuntimeException("Missing required column [$column].");
            }
        }
    }

    private function startBatch(
        City $city,
        string $entity,
        string $format,
        string $path,
        NetworkProvenance $provenance,
        ?User $actor,
    ): ImportBatch {
        return ImportBatch::create([
            'city_id' => $city->id,
            'user_id' => $actor?->id,
            'entity' => $entity,
            'format' => $format,
            'source_name' => basename($path),
            'provenance' => $provenance,
            'status' => 'running',
        ]);
    }

    private function finishBatch(ImportBatch $batch): ImportBatch
    {
        $batch->forceFill([
            'status' => ($batch->errors === null || $batch->errors === []) ? 'completed' : 'completed_with_errors',
            'completed_at' => now(),
        ])->save();

        return $batch;
    }

    private function recordError(ImportBatch $batch, int $line, string $message): void
    {
        $errors = $batch->errors ?? [];

        // Cap the stored errors: a completely wrong file should not write a
        // multi-megabyte JSON blob into the batch row.
        if (count($errors) < 100) {
            $errors[] = ['line' => $line, 'message' => $message];
        }

        $batch->errors = $errors;
        $batch->skipped_rows++;
    }

    private function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return ($value === null || $value === '') ? null : $value;
    }

    private function intOrNull(?string $value): ?int
    {
        return ($value === null || trim($value) === '') ? null : (int) $value;
    }
}
