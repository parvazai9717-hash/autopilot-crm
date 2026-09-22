<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApproveTaskTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $reviewer;
    private User $employee;
    private Meeting $meeting;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Http::fake();

        $this->org = Organization::create([
            'name'     => 'Review Org',
            'timezone' => 'Asia/Karachi',
            'settings' => '{}',
        ]);

        $this->reviewer = User::create([
            'org_id'    => $this->org->id,
            'name'      => 'Reviewer Admin',
            'email'     => 'admin@review.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'status'    => 'active',
            'is_active' => true,
        ]);

        $this->employee = User::create([
            'org_id'    => $this->org->id,
            'name'      => 'Worker Employee',
            'email'     => 'worker@review.com',
            'password'  => bcrypt('password123'),
            'role'      => 'employee',
            'status'    => 'active',
            'is_active' => true,
        ]);

        $this->meeting = Meeting::create([
            'org_id'       => $this->org->id,
            'title'        => 'Sprint Planning',
            'status'       => 'extracted',
            'created_by'   => $this->reviewer->id,
            'meeting_date' => now()->format('Y-m-d'),
        ]);
    }

    public function test_single_task_approval_succeeds_when_eligible(): void
    {
        $task = Task::create([
            'org_id'      => $this->org->id,
            'meeting_id'  => $this->meeting->id,
            'owner_id'    => $this->employee->id,
            'owner_state' => 'resolved',
            'title'       => 'Valid Task',
            'status'      => 'pending_approval',
            'priority'    => 'high',
        ]);

        $response = $this->actingAs($this->reviewer)
            ->postJson("/api/tasks/{$task->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('task.status', 'assigned');
    }

    public function test_single_task_approval_fails_with_422_when_ineligible(): void
    {
        // Task missing owner
        $task = Task::create([
            'org_id'      => $this->org->id,
            'meeting_id'  => $this->meeting->id,
            'owner_id'    => null,
            'owner_state' => 'missing',
            'title'       => 'Ineligible Task',
            'status'      => 'pending_approval',
            'priority'    => 'high',
        ]);

        $response = $this->actingAs($this->reviewer)
            ->postJson("/api/tasks/{$task->id}/approve");

        $response->assertStatus(422)
            ->assertJsonStructure([
                'error' => ['code', 'message', 'blockers'],
            ])
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertContains('OWNER_REQUIRED', $response->json('error.blockers'));
    }

    public function test_review_summary_endpoint(): void
    {
        // 1 eligible task
        Task::create([
            'org_id'      => $this->org->id,
            'meeting_id'  => $this->meeting->id,
            'owner_id'    => $this->employee->id,
            'owner_state' => 'resolved',
            'title'       => 'Eligible Task',
            'status'      => 'pending_approval',
        ]);

        // 1 task missing owner
        Task::create([
            'org_id'      => $this->org->id,
            'meeting_id'  => $this->meeting->id,
            'owner_id'    => null,
            'owner_state' => 'missing',
            'title'       => 'No Owner',
            'status'      => 'pending_approval',
        ]);

        // 1 task ambiguous owner
        Task::create([
            'org_id'          => $this->org->id,
            'meeting_id'      => $this->meeting->id,
            'owner_id'        => null,
            'owner_ambiguous' => true,
            'owner_state'     => 'ambiguous',
            'title'           => 'Ambiguous Owner',
            'status'          => 'pending_approval',
        ]);

        $response = $this->actingAs($this->reviewer)
            ->getJson("/api/meetings/{$this->meeting->id}/review-summary");

        $response->assertStatus(200)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('pending', 3)
            ->assertJsonPath('eligible', 1)
            ->assertJsonPath('needs_owner', 2)
            ->assertJsonPath('ambiguous_owner', 1);
    }

    public function test_bulk_approve_only_approves_eligible_and_skips_ineligible(): void
    {
        // Task 1: Eligible
        $task1 = Task::create([
            'org_id'      => $this->org->id,
            'meeting_id'  => $this->meeting->id,
            'owner_id'    => $this->employee->id,
            'owner_state' => 'resolved',
            'title'       => 'Task 1 Eligible',
            'status'      => 'pending_approval',
        ]);

        // Task 2: Ineligible (no owner)
        $task2 = Task::create([
            'org_id'      => $this->org->id,
            'meeting_id'  => $this->meeting->id,
            'owner_id'    => null,
            'owner_state' => 'missing',
            'title'       => 'Task 2 Missing Owner',
            'status'      => 'pending_approval',
        ]);

        $response = $this->actingAs($this->reviewer)
            ->postJson("/api/meetings/{$this->meeting->id}/approve-all");

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('approved_count', 1)
            ->assertJsonPath('skipped_count', 1);

        $this->assertEquals('assigned', $task1->fresh()->status);
        $this->assertEquals('pending_approval', $task2->fresh()->status);
    }
}
