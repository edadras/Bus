<?php

namespace Tests\Feature\Network;

use App\Domain\Network\Enums\NetworkProvenance;
use App\Domain\Network\Models\BusLine;
use App\Domain\Network\Models\BusStop;
use App\Domain\Network\Models\City;
use App\Domain\Network\Services\NetworkImporter;
use App\Support\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * The import pipeline is how a real city's network gets in.
 *
 * The rule that matters most is provenance: anything imported without an
 * explicit `official` marking must stay labelled all the way to the
 * passenger's screen, so demo geometry can never be presented as the
 * published network.
 */
class NetworkImportTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private NetworkImporter $importer;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = $this->makeCity();
        $this->importer = app(NetworkImporter::class);
        $this->dir = sys_get_temp_dir().'/hamsafar-import-'.bin2hex(random_bytes(4));

        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    private function csv(string $contents, string $name = 'stops.csv'): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    /** @param array<int, array<int, string|int|float>> $rows */
    private function xlsx(array $rows, string $name = 'stops.xlsx'): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');

        $path = $this->dir.'/'.$name;
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    public function test_stops_import_from_csv(): void
    {
        $path = $this->csv(<<<'CSV'
        code,name,lat,lng
        S1,میدان قدس,27.1832,56.2666
        S2,پارک دباغ,27.1900,56.2700
        CSV);

        $batch = $this->importer->importStops($this->city, $path, NetworkProvenance::Official);

        $this->assertSame(2, $batch->total_rows);
        $this->assertSame(2, $batch->created_rows);
        $this->assertSame(2, BusStop::where('city_id', $this->city->id)->count());
        $this->assertSame('csv', $batch->format);
    }

    public function test_stops_import_from_an_excel_workbook(): void
    {
        // A transit department exports from Excel far more often than it hands
        // over a CSV, so this is the realistic path, not the exotic one.
        $path = $this->xlsx([
            ['code', 'name', 'lat', 'lng'],
            ['S1', 'میدان قدس', 27.1832, 56.2666],
            ['S2', 'پارک دباغ', 27.19, 56.27],
        ]);

        $batch = $this->importer->importStops($this->city, $path, NetworkProvenance::Official);

        $this->assertSame('xlsx', $batch->format);
        $this->assertSame(2, $batch->created_rows);

        $stop = BusStop::where('code', 'S1')->firstOrFail();
        $this->assertSame('میدان قدس', $stop->name);
        $this->assertEqualsWithDelta(27.1832, (float) $stop->lat, 0.00001);
    }

    public function test_a_spreadsheets_trailing_empty_rows_are_not_reported_as_errors(): void
    {
        $path = $this->xlsx([
            ['code', 'name', 'lat', 'lng'],
            ['S1', 'ایستگاه یک', 27.1832, 56.2666],
            ['', '', '', ''],
            ['', '', '', ''],
        ]);

        $batch = $this->importer->importStops($this->city, $path);

        $this->assertSame(1, $batch->total_rows);
        $this->assertSame(0, $batch->skipped_rows);
    }

    public function test_provenance_travels_with_the_data(): void
    {
        $path = $this->csv(<<<'CSV'
        code,name,lat,lng
        S1,ایستگاه نمونه,27.1832,56.2666
        CSV);

        $this->importer->importStops($this->city, $path);

        // Not passing --provenance=official must never yield official data.
        $this->assertSame(
            NetworkProvenance::Sample,
            BusStop::where('code', 'S1')->firstOrFail()->provenance,
        );
    }

    public function test_one_bad_row_does_not_abandon_the_rest_of_the_file(): void
    {
        $path = $this->csv(<<<'CSV'
        code,name,lat,lng
        S1,ایستگاه یک,27.1832,56.2666
        S2,,27.1900,56.2700
        S3,ایستگاه سه,27.2000,56.2800
        CSV);

        $batch = $this->importer->importStops($this->city, $path);

        $this->assertSame(3, $batch->total_rows);
        $this->assertSame(2, $batch->created_rows);
        $this->assertSame(1, $batch->skipped_rows);

        // The operator needs the line number to fix and re-run.
        $this->assertSame(3, $batch->errors[0]['line']);
    }

    public function test_re_importing_updates_rather_than_duplicates(): void
    {
        $first = $this->csv(<<<'CSV'
        code,name,lat,lng
        S1,نام قدیم,27.1832,56.2666
        CSV, 'first.csv');

        $second = $this->csv(<<<'CSV'
        code,name,lat,lng
        S1,نام جدید,27.1832,56.2666
        CSV, 'second.csv');

        $this->importer->importStops($this->city, $first);
        $batch = $this->importer->importStops($this->city, $second);

        $this->assertSame(1, $batch->updated_rows);
        $this->assertSame(1, BusStop::where('city_id', $this->city->id)->count());
        $this->assertSame('نام جدید', BusStop::where('code', 'S1')->firstOrFail()->name);
    }

    public function test_lines_import_from_a_spreadsheet(): void
    {
        $path = $this->xlsx([
            ['code', 'name', 'headway_minutes'],
            ['102', 'خط ۱۰۲', 12],
        ], 'lines.xlsx');

        $batch = $this->importer->importLines($this->city, $path, NetworkProvenance::Official);

        $this->assertSame(1, $batch->created_rows);
        $this->assertSame(12, BusLine::where('code', '102')->firstOrFail()->headway_minutes);
    }

    public function test_an_unreadable_file_fails_loudly(): void
    {
        try {
            $this->importer->importStops($this->city, $this->dir.'/nope.csv');
            $this->fail('A missing import file must be refused.');
        } catch (DomainException $e) {
            // The message is translated; the code is what clients switch on.
            $this->assertSame('import_file_unreadable', $e->errorCode());
        }
    }

    public function test_a_clean_import_reports_zero_skips_rather_than_nothing(): void
    {
        $path = $this->csv(<<<'CSV'
        code,name,lat,lng
        S1,ایستگاه یک,27.1832,56.2666
        CSV);

        $batch = $this->importer->importStops($this->city, $path);

        // The counters are incremented in memory and read straight back, so
        // an untouched one must be 0, not null.
        $this->assertSame(0, $batch->skipped_rows);
        $this->assertSame(0, $batch->updated_rows);
        $this->assertSame(1, $batch->created_rows);
    }

    public function test_the_import_command_runs_end_to_end(): void
    {
        $path = $this->csv(<<<'CSV'
        code,name,lat,lng
        S1,ایستگاه یک,27.1832,56.2666
        CSV);

        $this->artisan('transit:import', [
            'entity' => 'stops',
            'path' => $path,
            '--city' => $this->city->slug,
            '--provenance' => 'official',
        ])->assertSuccessful();

        $this->assertSame(
            NetworkProvenance::Official,
            BusStop::where('code', 'S1')->firstOrFail()->provenance,
        );
    }
}
