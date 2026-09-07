<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBlocker;
use App\Models\TaskDependency;
use App\Models\User;
use App\Services\TaskStateMachine;
use Carbon\Carbon;
use Database\Seeders\MeetingSeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\UserSeeder;
use Tests\TestCase;

/**
 * Phase 11 — Executive Dashboard tests per Section 17 of SPEC.md.
 *
 * Requirements:
 *   - Executive and Admin roles only (employees and managers receive 403)
 *   - Six live counts: active, completed_today, overdue, blocked, at_risk, awaiting_approval
 *   - Three exception lists:
 *       1. Critical blockers (who is waiting on whom, duration)
 *       2. At risk (computed on read: tasks not complete with active dependency on overdue task)
 *       3. Awaiting approval (meetings extracted but not yet reviewed)
 *   - Overdue uses org timezone rule (Carbon::now($org->timezone)->startOfDay())
 */
class ExecutiveDashboardTest extends TestCase
{
    protected Organization $org;
    protected User         $executive; // Imran Malik (ID 7)
    protected User         $admin;     // Ahmad Ameen (ID 6)
    protected User         $manager;   // Bilal Sheikh (ID 5)
    protected User         $employee;  // Ahmed Raza (ID 1)

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OrganizationSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(MeetingSeeder::class);

        $this->org       = Organization::find(1);
        $this->executive = User::find(7); // exec@test.com
        $this->admin     = User::find(6); // admin@test.com
        $this->manager   = User::find(5); // bilal@test.com
        $this->employee  = User::find(1); // ahmed@test.com
    }

    // -------------------------------------------------------------------------
    // Auth & Role Gates
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/executive/dashboard');
        $response->assertStatus(401);
    }

    public function test_employee_cannot_access_executive_dashboard(): void
    {
        $response = $this->actingAs($this->employee)
            ->getJson('/api/executive/dashboard');

        $response->assertStatus(403);
    }

    public function test_manager_cannot_access_executive_dashboard(): void
    {
        $response = $this->actingAs($this->manager)
            ->getJson('/api/executive/dashboard');

        $response->assertStatus(403);
    }

    public function test_executive_and_admin_can_access_dashboard(): void
    {
        // Executive access
        $resExec = $this->actingAs($this->executive)
            ->getJson('/api/executive/dashboard');
        $resExec->assertStatus(200)
            ->assertJsonStructure([
                'counts' => [
                    'active',
                    'completed_today',
                    'overdue',
                    'blocked',
                    'at_risk',
                    'awaiting_approval',
                ],
                'exceptions' => [
                    'critical_blockers',
                    'at_risk',
                    'awaiting_approval',
                ],
            ]);

        // Admin access
        $resAdmin = $this->actingAs($this->admin)
            ->getJson('/api/executive/dashboard');
        $resAdmin->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // Six KPI Counts & Timezone Verification
    // -------------------------------------------------------------------------

    public function test_dashboard_kpi_counts_are_accurate_and_dynamic(): void
    {
        $tz = $this->org->timezone ?: 'Asia/Karachi';
        $today = Carbon::now($tz)->startOfDay();

        // Create an active in_progress task
        Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => 'Exec Active Task',
            'owner_id'   => $this->employee->id,
            'status'     => TaskStateMachine::STATUS_IN_PROGRESS,
            'priority'   => 'high',
            'due_date'   => $today->copy()->addDays(3)->format('Y-m-d'),
            'created_by' => $this->executive->id,
        ]);

        // Create an overdue task (due yesterday)
        Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => 'Exec Overdue Task',
            'owner_id'   => $this->employee->id,
            'status'     => TaskStateMachine::STATUS_ASSIGNED,
            'priority'   => 'high',
            'due_date'   => $today->copy()->subDay()->format('Y-m-d'),
            'created_by' => $this->executive->id,
        ]);

        // Create a task completed today
        Task::withoutGlobalScopes()->create([
            'org_id'       => $this->org->id,
            'title'        => 'Exec Completed Today',
            'owner_id'     => $this->employee->id,
            'status'       => TaskStateMachine::STATUS_COMPLETED,
            'priority'     => 'low',
            'due_date'     => $today->format('Y-m-d'),
            'completed_at' => now(),
            'created_by'   => $this->executive->id,
        ]);

        $response = $this->actingAs($this->executive)
            ->getJson('/api/executive/dashboard');

        $response->assertStatus(200);
        $counts = $response->json('counts');

        $this->assertGreaterThanOrEqual(2, $counts['active']);
        $this->assertGreaterThanOrEqual(1, $counts['overdue']);
        $this->assertGreaterThanOrEqual(1, $counts['completed_today']);
        $this->assertGreaterThanOrEqual(1, $counts['awaiting_approval']);
    }

    // -------------------------------------------------------------------------
    // At Risk Computation (Computed on read)
    // -------------------------------------------------------------------------

    public function test_at_risk_tasks_computed_dynamically_on_read(): void
    {
        $tz = $this->org->timezone ?: 'Asia/Karachi';
        $today = Carbon::now($tz)->startOfDay();

        // 1. Upstream Task A (Overdue)
        $upstreamTask = Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => 'Upstream Database Migration',
            'owner_id'   => $this->employee->id,
            'status'     => TaskStateMachine::STATUS_IN_PROGRESS,
            'priority'   => 'high',
            'due_date'   => $today->copy()->subDays(2)->format('Y-m-d'), // Overdue!
            'created_by' => $this->executive->id,
        ]);

        // 2. Downstream Task B (Open, dependent on Task A)
        $downstreamTask = Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => 'Downstream UI Integration',
            'owner_id'   => $this->manager->id,
            'status'     => TaskStateMachine::STATUS_ASSIGNED,
            'priority'   => 'medium',
            'due_date'   => $today->copy()->addDays(5)->format('Y-m-d'), // Not overdue itself
            'created_by' => $this->executive->id,
        ]);

        // 3. Declare active dependency: Task B depends on Task A
        TaskDependency::withoutGlobalScopes()->create([
            'task_id'            => $downstreamTask->id,
            'depends_on_task_id' => $upstreamTask->id,
            'dependency_type'    => 'blocks',
            'status'             => 'active',
            'created_by'         => $this->executive->id,
            'created_at'         => now(),
        ]);

        // Check dashboard: Downstream Task B must be marked AT RISK
        $response = $this->actingAs($this->executive)
            ->getJson('/api/executive/dashboard');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('counts.at_risk'));

        $atRiskIds = collect($response->json('exceptions.at_risk'))->pluck('id')->all();
        $this->assertContains($downstreamTask->id, $atRiskIds);

        // Verify the blocking dependency is reported
        $item = collect($response->json('exceptions.at_risk'))->firstWhere('id', $downstreamTask->id);
        $this->assertNotEmpty($item['blocking_dependencies']);
        $this->assertEquals($upstreamTask->id, $item['blocking_dependencies'][0]['task_id']);

        // Now resolve: Complete the upstream Task A
        $upstreamTask->status = TaskStateMachine::STATUS_COMPLETED;
        $upstreamTask->completed_at = now();
        $upstreamTask->save();

        // Next dashboard read: Task B is NO LONGER at risk!
        $responseAfter = $this->actingAs($this->executive)
            ->getJson('/api/executive/dashboard');

        $atRiskIdsAfter = collect($responseAfter->json('exceptions.at_risk'))->pluck('id')->all();
        $this->assertNotContains($downstreamTask->id, $atRiskIdsAfter);
    }

    // -------------------------------------------------------------------------
    // Exception Lists: Critical Blockers & Awaiting Approval
    // -------------------------------------------------------------------------

    public function test_critical_blockers_exception_list(): void
    {
        // Create a blocked task
        $blockedTask = Task::withoutGlobalScopes()->create([
            'org_id'     => $this->org->id,
            'title'      => 'Critical Client Integration',
            'owner_id'   => $this->employee->id,
            'status'     => TaskStateMachine::STATUS_BLOCKED,
            'priority'   => 'high',
            'due_date'   => now()->addDays(2)->format('Y-m-d'),
            'created_by' => $this->executive->id,
        ]);

        TaskBlocker::withoutGlobalScopes()->create([
            'task_id'     => $blockedTask->id,
            'reason_code' => 'waiting_for_approval',
            'description' => 'Waiting on client security sign-off',
            'blocked_by'  => $this->employee->id,
            'created_at'  => now()->subHours(5),
        ]);

        $response = $this->actingAs($this->executive)
            ->getJson('/api/executive/dashboard');

        $response->assertStatus(200);
        $blockerIds = collect($response->json('exceptions.critical_blockers'))->pluck('id')->all();
        $this->assertContains($blockedTask->id, $blockerIds);

        $blockerItem = collect($response->json('exceptions.critical_blockers'))->firstWhere('id', $blockedTask->id);
        $this->assertEquals('waiting_for_approval', $blockerItem['reason_code']);
        $this->assertEquals('Waiting on client security sign-off', $blockerItem['description']);
        $this->assertNotNull($blockerItem['blocked_duration']);
    }

    public function test_awaiting_approval_meetings_exception_list(): void
    {
        // Meeting 1 is seeded with status = extracted
        $response = $this->actingAs($this->executive)
            ->getJson('/api/executive/dashboard');

        $response->assertStatus(200);
        $meetingIds = collect($response->json('exceptions.awaiting_approval'))->pluck('id')->all();
        $this->assertContains(1, $meetingIds);

        $meeting = collect($response->json('exceptions.awaiting_approval'))->firstWhere('id', 1);
        $this->assertEquals('/meetings/1/review', $meeting['review_url']);
        $this->assertGreaterThanOrEqual(1, $meeting['pending_tasks_count']);

        // When meeting is reviewed, it leaves the exception list
        Meeting::withoutGlobalScopes()->where('id', 1)->update(['status' => 'reviewed']);

        $responseAfter = $this->actingAs($this->executive)
            ->getJson('/api/executive/dashboard');

        $meetingIdsAfter = collect($responseAfter->json('exceptions.awaiting_approval'))->pluck('id')->all();
        $this->assertNotContains(1, $meetingIdsAfter);
    }
}
