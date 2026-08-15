<?php

namespace Tests\Feature\Support;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Network\Models\City;
use App\Domain\Support\Models\Complaint;
use App\Domain\Support\Services\ComplaintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The complaint workbench: the agent's side of a passenger's report.
 *
 * Two properties are worth more than the rest. A photo attached to a complaint
 * is often the whole complaint, and it lives on the private disk — so it must
 * be reachable by staff through a signed link and by nobody else. And an
 * internal note must never reach the passenger's thread, whatever else changes
 * about how threads are read.
 */
class ComplaintWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private User $agent;

    private User $passenger;

    private Complaint $complaint;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->city = $this->makeCity();
        $this->passenger = $this->makePassenger($this->city);
        $this->agent = User::factory()->create(['city_id' => $this->city->id, 'first_name' => 'نازنین']);

        $this->complaint = $this->fileComplaint();

        $this->actingAsAdmin($this->agent, Role::SUPPORT_AGENT);
    }

    private function fileComplaint(): Complaint
    {
        $uuid = $this->actingAsPassenger($this->passenger)
            ->postJson('/api/v1/complaints', [
                'category' => 'driver',
                'subject' => 'رفتار نامناسب راننده',
                'body' => 'راننده در ایستگاه توقف نکرد.',
                'attachments' => [UploadedFile::fake()->image('bus.jpg')],
            ])->assertCreated()->json('data.uuid');

        return Complaint::where('uuid', $uuid)->firstOrFail();
    }

    public function test_the_thread_lists_its_attachments(): void
    {
        $attachments = $this->getJson("/api/v1/admin/complaints/{$this->complaint->uuid}")
            ->assertOk()
            ->json('data.attachments');

        $this->assertCount(1, $attachments);
        $this->assertSame('bus.jpg', $attachments[0]['original_name']);
    }

    public function test_an_attachment_is_served_only_through_a_signed_link(): void
    {
        $id = $this->complaint->attachments()->firstOrFail()->id;

        $url = $this->getJson("/api/v1/admin/complaints/{$this->complaint->uuid}/attachments/{$id}")
            ->assertOk()
            ->json('data.url');

        $this->get($url)->assertOk();

        // The regression this pins: the download route sat below the admin
        // panel's `/admin/{any}` catch-all, so an unsigned request quietly
        // returned the panel's HTML with a 200 and the signature never
        // checked. Stripping the query must now be refused outright.
        $this->get(strtok($url, '?'))->assertForbidden();
    }

    public function test_the_assignee_roster_lists_colleagues_who_handle_complaints(): void
    {
        $colleague = User::factory()->create(['city_id' => $this->city->id, 'first_name' => 'پویا']);
        $colleague->assignRole(Role::SUPPORT_AGENT, $this->city->id);

        // A driver manager does not work this queue and must not appear.
        $unrelated = User::factory()->create(['city_id' => $this->city->id, 'first_name' => 'کاوه']);
        $unrelated->assignRole(Role::DRIVER_MANAGER, $this->city->id);

        $uuids = collect($this->getJson('/api/v1/admin/complaints/assignees')->assertOk()->json('data'))
            ->pluck('uuid');

        $this->assertContains($colleague->uuid, $uuids);
        $this->assertNotContains($unrelated->uuid, $uuids);
    }

    public function test_the_roster_never_publishes_a_mobile_number(): void
    {
        $this->passenger->forceFill(['mobile' => '989121234567'])->save();

        $content = $this->getJson('/api/v1/admin/complaints/assignees')->assertOk()->getContent();

        $this->assertStringNotContainsString('989121234567', $content);
    }

    public function test_assigning_a_complaint_moves_it_out_of_new_and_names_the_owner(): void
    {
        $this->assertSame('new', $this->complaint->status->value);

        $this->postJson("/api/v1/admin/complaints/{$this->complaint->uuid}/assign", [
            'user_uuid' => $this->agent->uuid,
        ])->assertOk();

        $detail = $this->getJson("/api/v1/admin/complaints/{$this->complaint->uuid}")->assertOk();

        // A complaint that has an owner is no longer merely "new"; leaving it
        // there is how one sits in the queue for a week.
        $detail->assertJsonPath('data.complaint.status', 'reviewing');
        $detail->assertJsonPath('data.assignee.uuid', $this->agent->uuid);
    }

    public function test_the_queue_shows_who_owns_each_complaint(): void
    {
        $this->postJson("/api/v1/admin/complaints/{$this->complaint->uuid}/assign", [
            'user_uuid' => $this->agent->uuid,
        ])->assertOk();

        $this->getJson('/api/v1/admin/complaints')
            ->assertOk()
            ->assertJsonPath('data.0.assignee.name', $this->agent->name);
    }

    public function test_an_internal_note_never_reaches_the_passengers_thread(): void
    {
        $this->postJson("/api/v1/admin/complaints/{$this->complaint->uuid}/reply", [
            'body' => 'راننده قبلاً هم گزارش شده — پرونده پرسنلی بررسی شود.',
            'internal' => true,
        ])->assertCreated();

        $this->postJson("/api/v1/admin/complaints/{$this->complaint->uuid}/reply", [
            'body' => 'موضوع در حال بررسی است.',
        ])->assertCreated();

        $staffThread = $this->getJson("/api/v1/admin/complaints/{$this->complaint->uuid}")
            ->assertOk()
            ->json('data.thread');

        $this->assertTrue(collect($staffThread)->contains(fn ($m) => $m['is_internal'] === true));

        $passengerBodies = collect(
            $this->actingAsPassenger($this->passenger)
                ->getJson("/api/v1/complaints/{$this->complaint->uuid}")
                ->assertOk()
                ->json('data.messages'),
        )->pluck('body');

        $this->assertTrue($passengerBodies->contains('موضوع در حال بررسی است.'));
        $this->assertFalse($passengerBodies->contains(fn ($body) => str_contains($body, 'پرونده پرسنلی')));
    }

    public function test_a_passenger_never_reaches_the_staff_endpoints(): void
    {
        $this->actingAsPassenger($this->passenger);

        $this->getJson("/api/v1/admin/complaints/{$this->complaint->uuid}")->assertForbidden();
        $this->getJson('/api/v1/admin/complaints/assignees')->assertForbidden();
    }

    public function test_another_citys_complaint_is_not_reachable(): void
    {
        $other = City::factory()->create(['slug' => 'shiraz']);

        $stranger = app(ComplaintService::class)->create(
            User::factory()->create(['city_id' => $other->id]),
            [
                'category' => 'driver',
                'subject' => 'شکایت شهر دیگر',
                'body' => 'این شکایت متعلق به شهر دیگری است.',
                'city_id' => $other->id,
            ],
        );

        $this->getJson("/api/v1/admin/complaints/{$stranger->uuid}")->assertNotFound();
    }
}
