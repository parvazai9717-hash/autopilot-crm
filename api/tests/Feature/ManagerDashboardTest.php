<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBlocker;
use App\Models\TaskComment;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskStateMachine;
use Database\Seeders\MeetingSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\UserSeeder;
use Tests\TestCase;

/**
 * Phase 10 — Manager Dashboard tests per Section 17 of SPEC.md.
 *
 * Scope: Direct reports only (users.manager_id = me).
 * Tests cover:
 *   - Auth guard & Role restriction (employee receives 403)
 *   - Dashboard index: direct reports roster, metrics, team tasks
 *   - Reassign task to another direct report succeeds (logs ASSIGNED event)
 *   - Reassign task to someone outside direct reports returns 422
 *   - Change deadline updates due_date (logs DEADLINE_CHANGED event)
 *   - Comment adds task comment and logs COMMENTED event
 *   - Resolve blocker restores employee's previous_status and resolves blocker row
 *   - Manual escalate updates escalation_level and logs ESCALATED event
 *   - Cross-tenant / non-direct-report task access returns 404
 */
class ManagerDashboardTest extends TestCase
{
    protected Organization $org;
    protected User         $manager;      // Bilal (ID 5)
    protected User         $directReport1; // Ahmed (ID 1)
    protected User         $directReport2; // Sarah (ID 2)
    protected User         $otherEmployee;// Outside Bilal's reports
    protected Task         $reportTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OrganizationSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(MeetingSeeder::class);

        $this->org           = Organization::find(1);
        $this->manager       = User::find(5); // Bilal Sheikh (manager)
        $this->directReport1 = User::find(1); // Ahmed Raza (reports to Bilal)
        $this->directReport2 = User::find(2); // Sarah Khan (reports to Bilal)

        // Employee that reports to someone else (or no manager)
        $this->otherEmployee = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'external@test.com'],
            [
                'org_id'     => $this->org->id,
                'name'       => 'External Worker',
                'password'   => bcrypt('password123'),
                'role'       => 'employee',
                'manager_id' => 6, // Reports to Admin, not Bilal
                'status'     => 'active',
            ]
        );

        // Create an assigned task owned by Ahmed
        $this->reportTask = Task::withoutGlobalScopes()->create([
            'org_id'      => $this->org->id,
            'title'       => 'Ahmed Direct Report Task',
            'description' => 'A task for manager dashboard testing',
            'owner_id'    => $this->directReport1->id,
            'status'      => TaskStateMachine::STATUS_ASSIGNED,
            'priority'    => 'high',
            'due_date'    => now()->addDays(2)->toDateString(),
            'created_by'  => $this->manager->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Auth & Role Gates
    // ---------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/manager/dashboard');
        $response->assertStatus(401);
    }

    public function test_employee_cannot_access_manager_dashboard(): void
    {
        $response = $this->actingAs($this->directReport1)
            ->getJson('/api/manager/dashboard');

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // Dashboard Index & Direct Reports Scoping
    // ---------------------------------------------------------------

    public function test_manager_dashboard_returns_direct_reports_and_team_tasks(): void
    {
        // Create an external task owned by otherEmployee
        Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => 'Other Report Task',
            'owner_id'   => $this->otherEmployee->id,
            'status'     => TaskStateMachine::STATUS_ASSIGNED,
            'priority'   => 'medium',
            'due_date'   => now()->addDays(5)->toDateString(),
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager)
            ->getJson('/api/manager/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'metrics' => [
                    'total_tasks',
                    'completed_tasks',
                    'overdue_tasks',
                    'blocked_tasks',
                    'upcoming_tasks',
                    'completion_rate',
                ],
                'direct_reports',
                'tasks',
            ]);

        // Ensure direct reports roster contains Ahmed and Sarah, but NOT otherEmployee
        $reportIds = collect($response->json('direct_reports'))->pluck('id')->all();
        $this->assertContains($this->directReport1->id, $reportIds);
        $this->assertContains($this->directReport2->id, $reportIds);
        $this->assertNotContains($this->otherEmployee->id, $reportIds);

        // Ensure tasks list contains Ahmed's task but NOT otherEmployee's task
        $taskTitles = collect($response->json('tasks'))->pluck('title')->all();
        $this->assertContains('Ahmed Direct Report Task', $taskTitles);
        $this->assertNotContains('Other Report Task', $taskTitles);
    }

    // ---------------------------------------------------------------
    // Reassign
    // ---------------------------------------------------------------

    public function test_manager_can_reassign_task_to_another_direct_report(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson("/api/manager/tasks/{$this->reportTask->id}/reassign", [
                'owner_id' => $this->directReport2->id, // Sarah
            ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('tasks', [
            'id'       => $this->reportTask->id,
            'owner_id' => $this->directReport2->id,
        ]);

        $this->assertDatabaseHas('task_events', [
            'task_id'    => $this->reportTask->id,
            'event_type' => 'ASSIGNED',
            'actor_id'   => $this->manager->id,
        ]);
    }

    public function test_manager_cannot_reassign_to_someone_outside_direct_reports(): void
    {
        // Attempt to reassign to otherEmployee (who does not report to Bilal)
        $response = $this->actingAs($this->manager)
            ->postJson("/api/manager/tasks/{$this->reportTask->id}/reassign", [
                'owner_id' => $this->otherEmployee->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_OWNER');

        // Task owner should remain Ahmed
        $this->assertDatabaseHas('tasks', [
            'id'       => $this->reportTask->id,
            'owner_id' => $this->directReport1->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Change Deadline
    // ---------------------------------------------------------------

    public function test_manager_can_change_task_deadline(): void
    {
        $newDeadline = now()->addDays(10)->toDateString();

        $response = $this->actingAs($this->manager)
            ->patchJson("/api/manager/tasks/{$this->reportTask->id}/deadline", [
                'due_date' => $newDeadline,
            ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('tasks', [
            'id'       => $this->reportTask->id,
            'due_date' => $newDeadline,
        ]);

        $this->assertDatabaseHas('task_events', [
            'task_id'    => $this->reportTask->id,
            'event_type' => 'DEADLINE_CHANGED',
            'actor_id'   => $this->manager->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Comment
    // ---------------------------------------------------------------

    public function test_manager_can_comment_on_direct_report_task(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson("/api/manager/tasks/{$this->reportTask->id}/comment", [
                'body' => 'Checking in on this for the weekly client sync.',
            ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $this->reportTask->id,
            'user_id' => $this->manager->id,
            'body'    => 'Checking in on this for the weekly client sync.',
        ]);

        $this->assertDatabaseHas('task_events', [
            'task_id'    => $this->reportTask->id,
            'event_type' => 'COMMENTED',
            'actor_id'   => $this->manager->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Resolve Blocker
    // ---------------------------------------------------------------

    public function test_manager_resolving_blocker_restores_previous_status(): void
    {
        // First, block the task (from assigned)
        $this->actingAs($this->directReport1)
            ->postJson("/api/my-tasks/{$this->reportTask->id}/block", [
                'reason_code' => 'waiting_for_approval',
                'description' => 'Need budget approval from finance',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('tasks', [
            'id'              => $this->reportTask->id,
            'status'          => TaskStateMachine::STATUS_BLOCKED,
            'previous_status' => TaskStateMachine::STATUS_ASSIGNED,
            'owner_id'        => $this->directReport1->id,
        ]);

        // Manager resolves the blocker
        $response = $this->actingAs($this->manager)
            ->postJson("/api/manager/tasks/{$this->reportTask->id}/resolve-blocker");

        $response->assertStatus(200)->assertJsonPath('ok', true);

        // Status restored to previous_status ('assigned'), owner is NOT altered!
        $this->assertDatabaseHas('tasks', [
            'id'              => $this->reportTask->id,
            'status'          => TaskStateMachine::STATUS_ASSIGNED,
            'previous_status' => null,
            'owner_id'        => $this->directReport1->id,
        ]);

        // Blocker row marked resolved
        $this->assertDatabaseMissing('task_blockers', [
            'task_id'     => $this->reportTask->id,
            'resolved_at' => null,
        ]);

        // UNBLOCKED event logged
        $this->assertDatabaseHas('task_events', [
            'task_id'    => $this->reportTask->id,
            'event_type' => 'UNBLOCKED',
            'actor_id'   => $this->manager->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Manual Escalation
    // ---------------------------------------------------------------

    public function test_manager_can_manually_escalate_task(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson("/api/manager/tasks/{$this->reportTask->id}/escalate", [
                'escalation_level' => 2,
            ]);

        $response->assertStatus(200)->assertJsonPath('ok', true);

        $this->assertDatabaseHas('tasks', [
            'id'               => $this->reportTask->id,
            'escalation_level' => 2,
        ]);

        $this->assertDatabaseHas('task_events', [
            'task_id'    => $this->reportTask->id,
            'event_type' => 'ESCALATED',
            'actor_id'   => $this->manager->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Cross-Report / Isolation — 404 Not 403
    // ---------------------------------------------------------------

    public function test_manager_cannot_act_on_non_direct_report_task(): void
    {
        $nonReportTask = Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => 'Non report task',
            'owner_id'   => $this->otherEmployee->id,
            'status'     => TaskStateMachine::STATUS_ASSIGNED,
            'priority'   => 'low',
            'due_date'   => now()->addDays(3)->toDateString(),
            'created_by' => 6,
        ]);

        // Attempting to reassign non-report task must return 404
        $this->actingAs($this->manager)
            ->postJson("/api/manager/tasks/{$nonReportTask->id}/reassign", [
                'owner_id' => $this->directReport1->id,
            ])
            ->assertStatus(404);

        // Attempting to change deadline must return 404
        $this->actingAs($this->manager)
            ->patchJson("/api/manager/tasks/{$nonReportTask->id}/deadline", [
                'due_date' => now()->addDays(7)->toDateString(),
            ])
            ->assertStatus(404);

        // Attempting to resolve blocker must return 404
        $this->actingAs($this->manager)
            ->postJson("/api/manager/tasks/{$nonReportTask->id}/resolve-blocker")
            ->assertStatus(404);
    }
}
