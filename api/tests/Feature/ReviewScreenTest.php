<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use Database\Seeders\MeetingSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReviewScreenTest extends TestCase
{
    protected Organization $org;
    protected User $admin;
    protected User $employee;
    protected User $otherEmployee;
    protected Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OrganizationSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(MeetingSeeder::class);

        $this->org = Organization::find(1);
        $this->admin = User::find(6); // Ahmad Ameen (admin & meeting creator)
        $this->employee = User::find(1); // Ahmed Raza
        $this->otherEmployee = User::find(2); // Sarah Khan

        $this->meeting = Meeting::find(1);

        Config::set('autopilot.webhooks.tasks_approved', 'http://localhost:5678/webhook/tasks-approved');
        Config::set('autopilot.webhooks.signing_secret', 'test-signing-secret-123');
        Http::fake([
            'http://localhost:5678/webhook/*' => Http::response(['status' => 'received'], 200),
        ]);
    }

    public function test_admin_can_fetch_meeting_review_data(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/meetings/' . $this->meeting->id . '/review');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'meeting' => ['id', 'title', 'meeting_date', 'status', 'transcript', 'summary', 'creator'],
            'tasks' => [
                '*' => [
                    'id', 'title', 'description', 'owner_id', 'owner_name_raw',
                    'owner_ambiguous', 'due_date', 'deadline_phrase', 'priority',
                    'source_text', 'conditional', 'owner_confidence', 'deadline_confidence',
                    'status', 'owner',
                ],
            ],
            'users' => [
                '*' => ['id', 'name', 'email', 'role'],
            ],
            'stats' => ['total', 'pending', 'approved', 'rejected', 'ambiguous'],
        ]);

        $data = $response->json();
        $this->assertEquals(3, $data['stats']['total']);
        $this->assertEquals(3, $data['stats']['pending']);
        $this->assertEquals(1, $data['stats']['ambiguous']);

        // Check task 3 (Ali ambiguous case)
        $aliTask = collect($data['tasks'])->firstWhere('owner_name_raw', 'Ali');
        $this->assertNotNull($aliTask);
        $this->assertTrue($aliTask['owner_ambiguous']);
        $this->assertNull($aliTask['owner_id']);
    }

    public function test_non_creator_employee_cannot_access_review(): void
    {
        $response = $this->actingAs($this->employee)->getJson('/api/meetings/' . $this->meeting->id . '/review');

        $response->assertStatus(403);
        $response->assertJson([
            'error' => [
                'code' => 'FORBIDDEN',
            ],
        ]);
    }

    public function test_editing_title_does_not_silently_clear_owner_ambiguous(): void
    {
        $task = Task::find(3); // Ali ambiguous task
        $this->assertTrue((bool) $task->owner_ambiguous);

        $response = $this->actingAs($this->admin)->patchJson('/api/tasks/' . $task->id, [
            'title' => 'Updated Financial Report Title',
            'description' => 'Updated description text',
        ]);

        $response->assertStatus(200);
        $task->refresh();

        $this->assertEquals('Updated Financial Report Title', $task->title);
        $this->assertTrue((bool) $task->owner_ambiguous, 'Editing title must not clear owner_ambiguous flag');
        $this->assertNull($task->owner_id);

        // Verify audit event logged
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'event_type' => 'EDITED',
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_actively_assigning_owner_clears_owner_ambiguous(): void
    {
        $task = Task::find(3);
        $this->assertTrue((bool) $task->owner_ambiguous);

        // Actively assign Ali Khan (id: 3)
        $response = $this->actingAs($this->admin)->patchJson('/api/tasks/' . $task->id, [
            'owner_id' => 3,
        ]);

        $response->assertStatus(200);
        $task->refresh();

        $this->assertEquals(3, $task->owner_id);
        $this->assertFalse((bool) $task->owner_ambiguous, 'Assigning owner must clear owner_ambiguous flag');
    }

    public function test_cannot_approve_ambiguous_task_without_owner(): void
    {
        $task = Task::find(3);

        $response = $this->actingAs($this->admin)->postJson('/api/tasks/' . $task->id . '/approve');

        $response->assertStatus(422);
        $response->assertJson([
            'error' => [
                'code' => 'OWNER_REQUIRED',
                'field' => 'owner_id',
            ],
        ]);
    }

    public function test_single_task_approval_creates_events_and_assigns(): void
    {
        $task = Task::find(1); // Ahmed Raza task
        $this->assertEquals('pending_approval', $task->status);

        $response = $this->actingAs($this->admin)->postJson('/api/tasks/' . $task->id . '/approve');

        $response->assertStatus(200);
        $task->refresh();

        $this->assertEquals('assigned', $task->status);
        $this->assertEquals($this->admin->id, $task->approved_by);
        $this->assertNotNull($task->approved_at);

        // Verify task_events: APPROVED and ASSIGNED
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'event_type' => 'APPROVED',
            'actor_id' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'event_type' => 'ASSIGNED',
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_single_task_rejection(): void
    {
        $task = Task::find(2); // Sarah Khan task

        $response = $this->actingAs($this->admin)->postJson('/api/tasks/' . $task->id . '/reject', [
            'reason' => 'Not needed at this time',
        ]);

        $response->assertStatus(200);
        $task->refresh();

        $this->assertEquals('rejected', $task->status);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'event_type' => 'REJECTED',
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_approve_all_skips_ambiguous_cards_and_approves_valid_ones(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/meetings/' . $this->meeting->id . '/approve-all');

        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'approved_count' => 2, // Task 1 and Task 2
            'skipped_count' => 1,  // Task 3 (Ali ambiguous)
        ]);

        // Task 1 and 2 are assigned
        $this->assertEquals('assigned', Task::find(1)->status);
        $this->assertEquals('assigned', Task::find(2)->status);

        // Task 3 remains pending_approval
        $this->assertEquals('pending_approval', Task::find(3)->status);

        // Meeting remains extracted since Task 3 is pending
        $this->assertEquals('extracted', $this->meeting->fresh()->status);
    }

    public function test_approve_all_is_safe_against_double_clicks(): void
    {
        // First click
        $res1 = $this->actingAs($this->admin)->postJson('/api/meetings/' . $this->meeting->id . '/approve-all');
        $res1->assertStatus(200);
        $this->assertEquals(2, $res1->json('approved_count'));

        $eventCount1 = TaskEvent::where('event_type', 'APPROVED')->count();

        // Second click (duplicate call)
        $res2 = $this->actingAs($this->admin)->postJson('/api/meetings/' . $this->meeting->id . '/approve-all');
        $res2->assertStatus(200);
        $this->assertEquals(0, $res2->json('approved_count'));
        $this->assertEquals(1, $res2->json('skipped_count'));

        $eventCount2 = TaskEvent::where('event_type', 'APPROVED')->count();
        $this->assertEquals($eventCount1, $eventCount2, 'Duplicate approve-all calls must not duplicate task_events');
    }

    public function test_resolving_last_task_sets_meeting_reviewed_and_fires_webhook(): void
    {
        // 1. Approve All (approves Tasks 1 & 2)
        $this->actingAs($this->admin)->postJson('/api/meetings/' . $this->meeting->id . '/approve-all');

        // 2. Resolve Task 3 ambiguity by selecting Ali Raza (id: 4)
        $this->actingAs($this->admin)->patchJson('/api/tasks/3', ['owner_id' => 4]);

        // 3. Approve Task 3
        $response = $this->actingAs($this->admin)->postJson('/api/tasks/3/approve');
        $response->assertStatus(200);
        $response->assertJson([
            'ok' => true,
            'meeting_status' => 'reviewed',
            'is_meeting_resolved' => true,
        ]);

        // Meeting status updated
        $this->assertEquals('reviewed', $this->meeting->fresh()->status);

        // Outbound tasks.approved webhook delivery created
        $delivery = WebhookDelivery::where('event_type', 'tasks.approved')->latest('id')->first();
        $this->assertNotNull($delivery, 'Webhook tasks.approved must be dispatched when last card is resolved');
        $this->assertCount(3, $delivery->payload['tasks']);
    }
}
