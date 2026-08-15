<?php

namespace Tests\Feature\Api;

use App\Domain\Fleet\Models\Bus;
use App\Domain\Fleet\Models\BusAssignment;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The driver dossier: the screen an operator reads before letting somebody
 * carry passengers.
 *
 * The document routes are the delicate part. A licence scan and a national
 * card sit on the private disk, so they are only ever reachable through a
 * short-lived signed URL — and that URL must not be swallowed by the admin
 * panel's `/admin/{any}` catch-all, which is exactly what had happened to the
 * complaint attachment route sitting next to it.
 */
class DriverDossierTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->city = $this->makeCity();
        $this->driver = Driver::factory()->create(['city_id' => $this->city->id]);

        $this->actingAsAdmin(User::factory()->create(['city_id' => $this->city->id]));
    }

    private function upload(array $overrides = []): array
    {
        return $this->postJson("/api/v1/admin/drivers/{$this->driver->uuid}/documents", array_merge([
            'type' => 'license',
            'file' => UploadedFile::fake()->image('licence.jpg'),
        ], $overrides))->assertCreated()->json('data');
    }

    public function test_the_dossier_carries_documents_assignments_and_shifts(): void
    {
        $bus = Bus::factory()->inService()->create(['city_id' => $this->city->id]);
        BusAssignment::create([
            'bus_id' => $bus->id,
            'driver_id' => $this->driver->id,
            'starts_on' => today(),
            'is_active' => true,
        ]);
        $this->upload();

        $response = $this->getJson("/api/v1/admin/drivers/{$this->driver->uuid}")->assertOk();

        $this->assertCount(1, $response->json('data.documents'));
        $this->assertSame($bus->bus_number, $response->json('data.assignments.0.bus_number'));
        $this->assertIsArray($response->json('data.recent_shifts'));
    }

    public function test_an_expired_licence_is_reported_as_an_answer_not_a_date_to_compare(): void
    {
        $this->driver->forceFill(['license_expires_at' => today()->subDay()])->save();

        // The panel must not have to do date arithmetic to know a driver is
        // blocked — the server already refuses the shift on this basis.
        $this->getJson("/api/v1/admin/drivers/{$this->driver->uuid}")
            ->assertOk()
            ->assertJsonPath('data.driver.license_is_expired', true);
    }

    public function test_a_document_is_reachable_only_through_a_signed_url(): void
    {
        $document = $this->upload();

        $url = $this->getJson("/api/v1/admin/drivers/{$this->driver->uuid}/documents/{$document['id']}")
            ->assertOk()
            ->json('data.url');

        $this->get($url)->assertOk();

        // Strip the signature and the file must not come back. This is the
        // regression that matters: the route has to win against the panel's
        // catch-all, or an unsigned request would quietly return the shell.
        $this->get(strtok($url, '?'))->assertForbidden();
    }

    public function test_viewing_a_document_is_audited(): void
    {
        $document = $this->upload();

        $this->getJson("/api/v1/admin/drivers/{$this->driver->uuid}/documents/{$document['id']}")->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'fleet.driver.document_viewed']);
    }

    public function test_a_document_of_another_citys_driver_is_not_served(): void
    {
        $other = City::factory()->create(['slug' => 'shiraz']);
        $stranger = Driver::factory()->create(['city_id' => $other->id]);

        $this->getJson("/api/v1/admin/drivers/{$stranger->uuid}")->assertNotFound();
        $this->getJson("/api/v1/admin/drivers/{$stranger->uuid}/documents/1")->assertNotFound();
    }

    public function test_editing_a_driver_updates_the_licence_and_is_audited(): void
    {
        $this->patchJson("/api/v1/admin/drivers/{$this->driver->uuid}", [
            'license_number' => 'NEW-9931',
            'license_expires_at' => today()->addYear()->toDateString(),
        ])->assertOk()->assertJsonPath('data.license_number', 'NEW-9931');

        $this->assertDatabaseHas('audit_logs', ['action' => 'fleet.driver.updated']);
    }

    public function test_an_executable_upload_is_refused(): void
    {
        $this->postJson("/api/v1/admin/drivers/{$this->driver->uuid}/documents", [
            'type' => 'license',
            'file' => UploadedFile::fake()->create('payload.php', 12),
        ])->assertStatus(422);
    }

    public function test_the_dossier_is_closed_to_a_finance_manager(): void
    {
        $this->actingAsAdmin(
            User::factory()->create(['city_id' => $this->city->id]),
            Role::FINANCE_MANAGER,
        );

        $this->getJson("/api/v1/admin/drivers/{$this->driver->uuid}")->assertForbidden();
    }
}
