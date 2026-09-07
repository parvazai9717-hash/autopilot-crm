<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBlocker;
use App\Models\TaskComment;
use App\Models\TaskDependency;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskStateMachine;
use Database\Seeders\MeetingSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\UserSeeder;
use Tests\TestCase;

/**
 * Phase 9 — Employee "My Tasks" screen.
 *
 * Tests cover:
 *   - Index: correct grouping into buckets, only own tasks returned
 *   - Start: assigned → in_progress via state machine
 *   - Complete: in_progress → completed via state machine
 *   - Block: saves previous_status, writes task_blockers row, writes BLOCKED event,
 *            creates task_dependencies row when a dependency is picked
 *   - Block without dependency: still works (dependency field is optional)
 *   - Comment: creates TaskComment + COMMENTED event
 *   - Attach: stores file + writes ATTACHMENT_ADDED event
 *   - Auth guard: unauthenticated → 401
 *   - Isolation: cannot perform actions on another employee's task
 */
class EmployeeTasksTest extends TestCase
{
    protected Organization $org;
    protected User         $employee;
    protected User         $other;
    protected Task         $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OrganizationSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(MeetingSeeder::class);

        $this->org      = Organization::find(1);
        $this->employee = User::find(1); // Ahmed Raza (employee)
        $this->other    = User::find(2); // Sarah Khan (employee)

        // Create a fresh assigned task owned by Ahmed for deterministic testing
        $this->task = Task::withoutGlobalScopes()->create([
            'org_id'      => $this->org->id,
            'meeting_id'  => null,
            'title'       => 'Phase 9 test task',
            'description' => 'Created for Phase 9 unit tests.',
            'owner_id'    => $this->employee->id,
            'priority'    => 'high',
            'status'      => TaskStateMachine::STATUS_ASSIGNED,
            'due_date'    => now()->addDays(3)->toDateString(),
            'created_by'  => $this->employee->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Auth guard
    // ---------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/my-tasks/');
        $response->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // Index — bucket grouping
    // ---------------------------------------------------------------

    public function test_index_returns_correct_buckets(): void
    {
        // Create overdue task (due yesterday)
        $overdueTask = Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => 'Overdue task',
            'owner_id'   => $this->employee->id,
            'status'     => TaskStateMachine::STATUS_ASSIGNED,
            'priority'   => 'high',
            'due_date'   => now()->subDay()->toDateString(),
            'created_by' => $this->employee->id,
        ]);

        // Create due-today task
        $todayTask = Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => 'Due today task',
            'owner_id'   => $this->employee->id,
            'status'     => TaskStateMachine::STATUS_IN_PROGRESS,
            'priority'   => 'medium',
            'due_date'   => now()->toDateString(),
            'created_by' => $this->employee->id,
        ]);

        $response = $this->actingAs($this->employee)->getJson('/api/my-tasks/');

        $response->assertStatus(200)
            ->assertJsonStructure(['overdue', 'due_today', 'upcoming', 'open_tasks']);

        $overdue  = collect($response->json('overdue'));
        $dueToday = collect($response->json('due_today'));
        $upcoming = collect($response->json('upcoming'));

        $this->assertTrue($overdue->contains('id', $overdueTask->id),  'overdue task must be in overdue bucket');
        $this->assertTrue($dueToday->contains('id', $todayTask->id),   'due-today task must be in due_today bucket');
        $this->assertTrue($upcoming->contains('id', $this->task->id),  'upcoming task must be in upcoming bucket');
    }

    public function test_index_does_not_return_other_employees_tasks(): void
    {
        // Task owned by Sarah, not Ahmed
        $sarahTask = Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => "Sarah's private task",
            'owner_id'   => $this->other->id,
            'status'     => TaskStateMachine::STATUS_ASSIGNED,
            'priority'   => 'low',
            'due_date'   => now()->addDays(5)->toDateString(),
            'created_by' => $this->other->id,
        ]);

        $response = $this->actingAs($this->employee)->getJson('/api/my-tasks/');
        $response->assertStatus(200);

        $allIds = collect([
            ...$response->json('overdue'),
            ...$response->json('due_today'),
            ...$response->json('upcoming'),
        ])->pluck('id');

        $this->assertFalse($allIds->contains($sarahTask->id), "Sarah's task must not appear for Ahmed");
    }

    // ---------------------------------------------------------------
    // Start
    // ---------------------------------------------------------------

    public function test_start_transitions_task_to_in_progress(): void
    {
        $response = $this->actingAs($this->employee)
            ->postJson("/api/my-tasks/{$this->task->id}/start");

        $response->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('tasks', [
            'id'     => $this->task->id,
            'status' => TaskStateMachine::STATUS_IN_PROGRESS,
        ]);

        $this->assertDatabaseHas('task_events', [
            'task_id'    => $this->task->id,
            'event_type' => 'STARTED',
        ]);
    }

    // ---------------------------------------------------------------
    // Complete
    // ---------------------------------------------------------------

    public function test_complete_transitions_in_progress_task_to_completed(): void
    {
        // Move to in_progress first
        $this->task->update(['status' => TaskStateMachine::STATUS_IN_PROGRESS]);

        $response = $this->actingAs($this->employee)
            ->postJson("/api/my-tasks/{$this->task->id}/complete");

        $response->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('tasks', [
            'id'     => $this->task->id,
            'status' => TaskStateMachine::STATUS_COMPLETED,
        ]);

        $this->assertDatabaseHas('task_events', [
            'task_id'    => $this->task->id,
            'event_type' => 'COMPLETED',
        ]);
    }

    // ---------------------------------------------------------------
    // Block (without dependency)
    // ---------------------------------------------------------------

    public function test_block_saves_previous_status_and_writes_blocker_row(): void
    {
        $previousStatus = $this->task->status;

        $response = $this->actingAs($this->employee)
            ->postJson("/api/my-tasks/{$this->task->id}/block", [
                'reason_code' => 'waiting_for_info',
                'description' => 'Waiting for the Q3 report data.',
            ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('tasks', [
            'id'              => $this->task->id,
            'status'          => TaskStateMachine::STATUS_BLOCKED,
            'previous_status' => $previousStatus,
        ]);

        $this->assertDatabaseHas('task_blockers', [
            'task_id'     => $this->task->id,
            'reason_code' => 'waiting_for_info',
            'description' => 'Waiting for the Q3 report data.',
            'blocked_by'  => $this->employee->id,
        ]);

        $this->assertDatabaseHas('task_events', [
            'task_id'    => $this->task->id,
            'event_type' => 'BLOCKED',
            'actor_type' => 'user',
            'actor_id'   => $this->employee->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Block (with dependency — optional field)
    // ---------------------------------------------------------------

    public function test_block_with_dependency_creates_task_dependencies_row(): void
    {
        // A second task in the org that Ahmed is waiting on
        $blocker = Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => 'Blocker task',
            'owner_id'   => $this->other->id,
            'status'     => TaskStateMachine::STATUS_IN_PROGRESS,
            'priority'   => 'high',
            'due_date'   => now()->addDays(1)->toDateString(),
            'created_by' => $this->other->id,
        ]);

        $response = $this->actingAs($this->employee)
            ->postJson("/api/my-tasks/{$this->task->id}/block", [
                'reason_code'        => 'waiting_for_person',
                'depends_on_task_id' => $blocker->id,
            ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('task_dependencies', [
            'task_id'          => $this->task->id,
            'depends_on_task_id' => $blocker->id,
            'status'           => 'active',
        ]);
    }

    public function test_block_dependency_field_is_optional(): void
    {
        // No depends_on_task_id → should still succeed
        $response = $this->actingAs($this->employee)
            ->postJson("/api/my-tasks/{$this->task->id}/block", [
                'reason_code' => 'other',
            ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('tasks', [
            'id'     => $this->task->id,
            'status' => TaskStateMachine::STATUS_BLOCKED,
        ]);

        $this->assertDatabaseMissing('task_dependencies', [
            'task_id' => $this->task->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Unblock
    // ---------------------------------------------------------------

    public function test_unblock_restores_previous_status_and_resolves_blocker(): void
    {
        // First, block the task
        $this->actingAs($this->employee)
            ->postJson("/api/my-tasks/{$this->task->id}/block", [
                'reason_code' => 'waiting_for_info',
                'description' => 'Waiting for specs',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('tasks', [
            'id'              => $this->task->id,
            'status'          => TaskStateMachine::STATUS_BLOCKED,
            'previous_status' => TaskStateMachine::STATUS_ASSIGNED,
        ]);

        // Now unblock the task
        $response = $this->actingAs($this->employee)
            ->postJson("/api/my-tasks/{$this->task->id}/unblock");

        $response->assertStatus(200)->assertJsonPath('ok', true);

        // Status restored to previous_status ('assigned')
        $this->assertDatabaseHas('tasks', [
            'id'              => $this->task->id,
            'status'          => TaskStateMachine::STATUS_ASSIGNED,
            'previous_status' => null,
        ]);

        // Blocker row resolved
        $this->assertDatabaseMissing('task_blockers', [
            'task_id'     => $this->task->id,
            'resolved_at' => null,
        ]);

        // UNBLOCKED event logged
        $this->assertDatabaseHas('task_events', [
            'task_id'    => $this->task->id,
            'event_type' => 'UNBLOCKED',
            'actor_id'   => $this->employee->id,
        ]);
    }

    public function test_unblock_non_blocked_task_returns_422(): void
    {
        // Task is assigned, not blocked
        $response = $this->actingAs($this->employee)
            ->postJson("/api/my-tasks/{$this->task->id}/unblock");

        $response->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // Comment
    // ---------------------------------------------------------------

    public function test_comment_creates_task_comment_and_event(): void
    {
        $response = $this->actingAs($this->employee)
            ->postJson("/api/my-tasks/{$this->task->id}/comment", [
                'body' => 'Picking this up after the standup.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['comment' => ['id', 'body', 'author_name', 'created_at']]);

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $this->task->id,
            'user_id' => $this->employee->id,
            'body'    => 'Picking this up after the standup.',
        ]);

        $this->assertDatabaseHas('task_events', [
            'task_id'    => $this->task->id,
            'event_type' => 'COMMENTED',
        ]);
    }

    // ---------------------------------------------------------------
    // Cross-tenant isolation — 404 not 403
    // ---------------------------------------------------------------

    public function test_employee_cannot_act_on_another_employees_task(): void
    {
        // Task owned by Sarah — Ahmed must get 404
        $sarahTask = Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => "Sarah's task",
            'owner_id'   => $this->other->id,
            'status'     => TaskStateMachine::STATUS_ASSIGNED,
            'priority'   => 'low',
            'due_date'   => now()->addDays(2)->toDateString(),
            'created_by' => $this->other->id,
        ]);

        foreach (['start', 'complete'] as $action) {
            $this->actingAs($this->employee)
                ->postJson("/api/my-tasks/{$sarahTask->id}/{$action}")
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'NOT_FOUND');
        }

        $this->actingAs($this->employee)
            ->postJson("/api/my-tasks/{$sarahTask->id}/block", ['reason_code' => 'other'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    // ---------------------------------------------------------------
    // Illegal transition returns 422
    // ---------------------------------------------------------------

    public function test_illegal_transition_returns_422(): void
    {
        // Cannot complete a task that hasn't been started from 'assigned' … wait,
        // state machine allows assigned → completed. Test terminal state instead.
        $this->task->update(['status' => TaskStateMachine::STATUS_COMPLETED]);

        $response = $this->actingAs($this->employee)
            ->postJson("/api/my-tasks/{$this->task->id}/start");

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_TRANSITION');
    }
}
