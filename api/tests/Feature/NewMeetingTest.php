<?php

namespace Tests\Feature;

use App\Jobs\ProcessMeetingAudio;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NewMeetingTest extends TestCase
{
    protected Organization $org;
    protected User $admin;
    protected User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::firstOrCreate(
            ['id' => 1],
            ['name' => 'Demo Company', 'timezone' => 'Asia/Karachi']
        );

        $this->admin = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'admin@test.com'],
            [
                'org_id' => 1,
                'name' => 'Admin User',
                'password' => bcrypt('password'),
                'role' => 'admin',
                'status' => 'active',
            ]
        );

        $this->employee = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'ahmed@test.com'],
            [
                'org_id' => 1,
                'name' => 'Ahmed Raza',
                'password' => bcrypt('password'),
                'role' => 'employee',
                'status' => 'active',
            ]
        );
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/meetings', [
            'title' => 'Unauth Meeting',
            'transcript' => 'Some transcript',
        ]);

        $response->assertStatus(401);
    }

    public function test_employee_cannot_create_meeting_returns_403(): void
    {
        $response = $this->actingAs($this->employee)
            ->postJson('/api/meetings', [
                'title' => 'Employee Meeting',
                'transcript' => 'Employee transcript text',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_employee_cannot_upload_audio_returns_403(): void
    {
        $fakeAudio = UploadedFile::fake()->create('recording.mp3', 500, 'audio/mpeg');

        $response = $this->actingAs($this->employee)
            ->post('/api/meetings/upload', [
                'title' => 'Employee Audio',
                'file' => $fakeAudio,
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_admin_can_create_meeting_with_transcript(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->admin)
            ->postJson('/api/meetings', [
                'title' => 'Q4 Strategic Planning',
                'meeting_date' => '2026-09-06',
                'transcript' => 'Ahmed will prepare the ABC proposal by Friday. Sarah to review vendor agreement.',
                'summary' => 'Strategic planning on proposals and vendor agreements.',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'meeting_id',
                'title',
                'status',
                'meeting_date',
                'source',
            ])
            ->assertJson([
                'title' => 'Q4 Strategic Planning',
                'status' => 'processing',
                'source' => 'text',
            ]);

        $meetingId = $response->json('meeting_id');
        $this->assertDatabaseHas('meetings', [
            'id' => $meetingId,
            'org_id' => 1,
            'title' => 'Q4 Strategic Planning',
            'source' => 'text',
            'created_by' => $this->admin->id,
            'status' => 'processing',
        ]);

        // Check webhook delivery record created
        $this->assertDatabaseHas('webhook_deliveries', [
            'event_type' => 'meeting.uploaded',
        ]);
    }

    public function test_admin_can_upload_audio_file(): void
    {
        Queue::fake();

        $fakeAudio = UploadedFile::fake()->create('sync.mp3', 1000, 'audio/mpeg');

        $response = $this->actingAs($this->admin)
            ->post('/api/meetings/upload', [
                'title' => 'Executive Sync Audio',
                'meeting_date' => '2026-09-06',
                'file' => $fakeAudio,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'meeting_id',
                'title',
                'status',
                'source',
            ])
            ->assertJson([
                'title' => 'Executive Sync Audio',
                'status' => 'uploaded',
                'source' => 'upload',
            ]);

        $meetingId = $response->json('meeting_id');
        $this->assertDatabaseHas('meetings', [
            'id' => $meetingId,
            'org_id' => 1,
            'title' => 'Executive Sync Audio',
            'source' => 'upload',
            'created_by' => $this->admin->id,
            'status' => 'uploaded',
        ]);

        Queue::assertPushed(ProcessMeetingAudio::class, function ($job) use ($meetingId) {
            return $job->meetingId === $meetingId;
        });
    }

    public function test_get_meeting_status_returns_live_state(): void
    {
        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => 1,
            'title' => 'Status Check Meeting',
            'meeting_date' => '2026-09-06',
            'timezone' => 'Asia/Karachi',
            'source' => 'text',
            'created_by' => $this->admin->id,
            'transcript' => 'Test transcript',
            'status' => 'processing',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/meetings/{$meeting->id}/status");

        $response->assertStatus(200)
            ->assertJson([
                'id' => $meeting->id,
                'title' => 'Status Check Meeting',
                'status' => 'processing',
                'source' => 'text',
                'task_count' => 0,
            ]);
    }

    public function test_cannot_access_other_org_meeting_status_returns_404(): void
    {
        $otherOrg = Organization::firstOrCreate(
            ['name' => 'Foreign Corp'],
            ['timezone' => 'UTC', 'settings' => ['api_key' => 'secret_foreign']]
        );

        $foreignUser = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'foreign_unique@test.com'],
            [
                'org_id' => $otherOrg->id,
                'name' => 'Foreign User',
                'password' => bcrypt('password'),
                'role' => 'admin',
                'status' => 'active',
            ]
        );

        $foreignMeeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $otherOrg->id,
            'title' => 'Secret Other Org Meeting',
            'meeting_date' => '2026-09-06',
            'timezone' => 'UTC',
            'source' => 'text',
            'created_by' => $foreignUser->id,
            'status' => 'processing',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/meetings/{$foreignMeeting->id}/status");

        $response->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_admin_can_retry_failed_audio_meeting(): void
    {
        Queue::fake();

        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => 1,
            'title' => 'Failed Recording Meeting',
            'meeting_date' => '2026-09-06',
            'timezone' => 'Asia/Karachi',
            'source' => 'upload',
            'created_by' => $this->admin->id,
            'audio_path' => 'meetings/original/recording.mp3',
            'status' => 'failed',
            'error_message' => 'Conversion failed',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/meetings/{$meeting->id}/retry");

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'meeting_id' => $meeting->id,
                'status' => 'uploaded',
            ]);

        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'status' => 'uploaded',
            'error_message' => null,
        ]);

        Queue::assertPushed(ProcessMeetingAudio::class);
    }

    public function test_retry_meeting_without_audio_returns_422(): void
    {
        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => 1,
            'title' => 'Text Only Meeting',
            'meeting_date' => '2026-09-06',
            'timezone' => 'Asia/Karachi',
            'source' => 'text',
            'created_by' => $this->admin->id,
            'audio_path' => null,
            'status' => 'failed',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/meetings/{$meeting->id}/retry");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }
}
