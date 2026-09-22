<?php

namespace Tests\Feature;

use App\Domain\Tasks\TaskPredicates;
use App\Domain\Tasks\TaskStatus;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskPredicatesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;
    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name'     => 'Test Org',
            'timezone' => 'Asia/Karachi',
            'settings' => '{}',
        ]);

        $this->user = User::create([
            'org_id'    => $this->org->id,
            'name'      => 'Test User',
            'email'     => 'test@example.com',
            'password'  => bcrypt('secret'),
            'role'      => 'employee',
            'status'    => 'active',
            'is_active' => true,
        ]);

        $this->today = Carbon::now('Asia/Karachi')->startOfDay();
        $this->actingAs($this->user);
    }

    public function test_open_predicate_includes_only_active_work(): void
    {
        $assigned   = $this->createTask(['status' => 'assigned']);
        $inProgress = $this->createTask(['status' => 'in_progress']);
        $blocked    = $this->createTask(['status' => 'blocked']);
        $completed  = $this->createTask(['status' => 'completed']);
        $rejected   = $this->createTask(['status' => 'rejected']);
        $pending    = $this->createTask(['status' => 'pending_approval']);

        $openIds = TaskPredicates::open(Task::query())->pluck('id')->all();

        $this->assertContains($assigned->id, $openIds);
        $this->assertContains($inProgress->id, $openIds);
        $this->assertContains($blocked->id, $openIds);
        $this->assertNotContains($completed->id, $openIds);
        $this->assertNotContains($rejected->id, $openIds);
        $this->assertNotContains($pending->id, $openIds);
    }

    public function test_overdue_predicate_excludes_completed_and_rejected_tasks(): void
    {
        $pastDate = $this->today->copy()->subDays(2)->format('Y-m-d');

        $activeOverdue    = $this->createTask(['status' => 'assigned', 'due_date' => $pastDate]);
        $blockedOverdue   = $this->createTask(['status' => 'blocked', 'due_date' => $pastDate]);
        $completedPastDue = $this->createTask(['status' => 'completed', 'due_date' => $pastDate]);
        $rejectedPastDue  = $this->createTask(['status' => 'rejected', 'due_date' => $pastDate]);

        $overdueIds = TaskPredicates::overdue(Task::query(), $this->today)->pluck('id')->all();

        $this->assertContains($activeOverdue->id, $overdueIds);
        $this->assertContains($blockedOverdue->id, $overdueIds);
        $this->assertNotContains($completedPastDue->id, $overdueIds, 'Completed task with past due_date must NEVER be in overdue predicate.');
        $this->assertNotContains($rejectedPastDue->id, $overdueIds, 'Rejected task with past due_date must NEVER be in overdue predicate.');
    }

    public function test_due_today_and_upcoming_predicates(): void
    {
        $todayStr  = $this->today->format('Y-m-d');
        $futureStr = $this->today->copy()->addDays(3)->format('Y-m-d');

        $dueTodayTask = $this->createTask(['status' => 'assigned', 'due_date' => $todayStr]);
        $upcomingTask = $this->createTask(['status' => 'assigned', 'due_date' => $futureStr]);
        $noDateTask   = $this->createTask(['status' => 'assigned', 'due_date' => null]);

        $dueTodayIds  = TaskPredicates::dueToday(Task::query(), $this->today)->pluck('id')->all();
        $upcomingIds  = TaskPredicates::upcoming(Task::query(), $this->today)->pluck('id')->all();
        $noDeadlineIds = TaskPredicates::noDeadline(Task::query())->pluck('id')->all();

        $this->assertContains($dueTodayTask->id, $dueTodayIds);
        $this->assertNotContains($upcomingTask->id, $dueTodayIds);

        $this->assertContains($upcomingTask->id, $upcomingIds);
        $this->assertNotContains($dueTodayTask->id, $upcomingIds);

        $this->assertContains($noDateTask->id, $noDeadlineIds);
        $this->assertNotContains($dueTodayTask->id, $noDeadlineIds);
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
