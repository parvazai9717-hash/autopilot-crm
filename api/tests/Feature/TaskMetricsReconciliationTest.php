<?php

namespace Tests\Feature;

use App\Domain\Tasks\TaskMetrics;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskMetricsReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name'     => 'Reconciliation Org',
            'timezone' => 'Asia/Karachi',
            'settings' => '{}',
        ]);

        $this->user = User::create([
            'org_id'    => $this->org->id,
            'name'      => 'Reconciliation User',
            'email'     => 'recon@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'employee',
            'status'    => 'active',
            'is_active' => true,
        ]);

        $this->today = Carbon::now('Asia/Karachi')->startOfDay();
    }

    public function test_metrics_reconcile_mathematically(): void
    {
        $pastDate   = $this->today->copy()->subDays(3)->format('Y-m-d');
        $todayStr   = $this->today->format('Y-m-d');
        $futureDate = $this->today->copy()->addDays(5)->format('Y-m-d');

        // Overdue active tasks (2)
        $this->createTask(['status' => 'assigned', 'due_date' => $pastDate]);
        $this->createTask(['status' => 'blocked', 'due_date' => $pastDate]);

        // Due today active tasks (3)
        $this->createTask(['status' => 'assigned', 'due_date' => $todayStr]);
        $this->createTask(['status' => 'in_progress', 'due_date' => $todayStr]);
        $this->createTask(['status' => 'assigned', 'due_date' => $todayStr]);

        // Upcoming active tasks (4)
        $this->createTask(['status' => 'assigned', 'due_date' => $futureDate]);
        $this->createTask(['status' => 'in_progress', 'due_date' => $futureDate]);
        $this->createTask(['status' => 'assigned', 'due_date' => $futureDate]);
        $this->createTask(['status' => 'blocked', 'due_date' => $futureDate]);

        // No deadline active tasks (2)
        $this->createTask(['status' => 'assigned', 'due_date' => null]);
        $this->createTask(['status' => 'in_progress', 'due_date' => null]);

        // Terminal tasks (must NOT affect active counts)
        $this->createTask(['status' => 'completed', 'due_date' => $pastDate, 'completed_at' => now()]);
        $this->createTask(['status' => 'rejected', 'due_date' => $pastDate]);
        $this->createTask(['status' => 'pending_approval', 'due_date' => $pastDate]);

        $metrics = TaskMetrics::forOrg($this->org, $this->today);

        $this->assertEquals(2, $metrics['overdue']);
        $this->assertEquals(3, $metrics['due_today']);
        $this->assertEquals(4, $metrics['upcoming']);
        $this->assertEquals(2, $metrics['no_deadline']);
        $this->assertEquals(11, $metrics['active']);

        // Canonical invariant required by Brief Phase 1:
        // active = overdue + due_today + upcoming + no_deadline
        $this->assertSame(
            $metrics['active'],
            $metrics['overdue'] + $metrics['due_today'] + $metrics['upcoming'] + $metrics['no_deadline'],
            'Mathematical reconciliation invariant failed: active must equal overdue + due_today + upcoming + no_deadline'
        );
    }

    public function test_unassigned_active_count(): void
    {
        $this->createTask(['status' => 'assigned', 'owner_id' => null, 'due_date' => $this->today->format('Y-m-d')]);
        $this->createTask(['status' => 'assigned', 'owner_id' => $this->user->id, 'due_date' => $this->today->format('Y-m-d')]);

        $metrics = TaskMetrics::forOrg($this->org, $this->today);

        $this->assertEquals(1, $metrics['unassigned_active']);
    }

    public function test_status_audit_command_detects_anomalies(): void
    {
        // 1. Task with stored 'overdue'
        $this->createTask(['status' => 'overdue', 'title' => 'Bad Overdue Task']);

        // 2. Task with completed status but null completed_at
        $this->createTask(['status' => 'completed', 'completed_at' => null, 'title' => 'Missing CompletedAt']);

        $reportFile = storage_path('app/reports/test-status-audit.csv');
        if (file_exists($reportFile)) {
            unlink($reportFile);
        }

        $this->artisan('tasks:status-audit', ['--output' => $reportFile])
            ->assertSuccessful();

        $this->assertFileExists($reportFile);
        $content = file_get_contents($reportFile);
        $this->assertStringContainsString('INVALID_STORED_STATUS', $content);
        $this->assertStringContainsString('COMPLETED_WITHOUT_TIMESTAMP', $content);

        unlink($reportFile);
    }

    private function createTask(array $attributes): Task
    {
        return Task::create(array_merge([
            'org_id'   => $this->org->id,
            'owner_id' => $this->user->id,
            'title'    => 'Test Task ' . uniqid(),
            'status'   => 'assigned',
            'priority' => 'medium',
        ], $attributes));
    }
}
