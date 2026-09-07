<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBlocker;
use App\Models\TaskDependency;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskStateMachine;
use Tests\TestCase;

class TaskStateMachineTest extends TestCase
{
    protected TaskStateMachine $stateMachine;
    protected Organization $org;
    protected User $user;
    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\OrganizationSeeder::class);
        $this->seed(\Database\Seeders\UserSeeder::class);
        $this->stateMachine = new TaskStateMachine();
        $this->org = Organization::find(1);
        $this->user = User::withoutGlobalScopes()->where('id', 1)->first() ?? User::withoutGlobalScopes()->first();
        $this->manager = User::withoutGlobalScopes()->where('id', 5)->first() ?? $this->user;
    }

    private function createTask(array $attributes = []): Task
    {
        return Task::withoutGlobalScopes()->create(array_merge([
            'org_id' => $this->org->id,
            'title' => 'Test State Machine Task',
            'description' => 'Test description',
            'owner_id' => $this->user->id,
            'owner_name_raw' => $this->user->name,
            'owner_ambiguous' => false,
            'priority' => 'high',
            'status' => TaskStateMachine::STATUS_PENDING_APPROVAL,
            'due_date' => now()->addDays(3)->toDateString(),
            'escalation_level' => 0,
        ], $attributes));
    }

    public function test_standard_lifecycle_transitions_successfully(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_DETECTED]);

        // 1. detected -> pending_approval
        $task = $this->stateMachine->transition(
            $task,
            TaskStateMachine::STATUS_PENDING_APPROVAL,
            $this->user->id,
            'user'
        );
        $this->assertEquals(TaskStateMachine::STATUS_PENDING_APPROVAL, $task->status);

        // 2. pending_approval -> approved
        $task = $this->stateMachine->transition(
            $task,
            TaskStateMachine::STATUS_APPROVED,
            $this->user->id,
            'user'
        );
        $this->assertEquals(TaskStateMachine::STATUS_APPROVED, $task->status);
        $this->assertNotNull($task->approved_at);
        $this->assertEquals($this->user->id, $task->approved_by);

        // 3. approved -> assigned
        $task = $this->stateMachine->transition(
            $task,
            TaskStateMachine::STATUS_ASSIGNED,
            $this->user->id,
            'user'
        );
        $this->assertEquals(TaskStateMachine::STATUS_ASSIGNED, $task->status);

        // 4. assigned -> in_progress
        $task = $this->stateMachine->start($task, $this->user->id, 'user');
        $this->assertEquals(TaskStateMachine::STATUS_IN_PROGRESS, $task->status);
        $this->assertNotNull($task->started_at);

        // 5. in_progress -> completed
        $task = $this->stateMachine->complete($task, $this->user->id, 'user');
        $this->assertEquals(TaskStateMachine::STATUS_COMPLETED, $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_approve_with_auto_assign_creates_approved_and_assigned_events(): void
    {
        $task = $this->createTask([
            'status' => TaskStateMachine::STATUS_PENDING_APPROVAL,
            'owner_id' => $this->user->id,
            'owner_ambiguous' => false,
        ]);

        $approvedTask = $this->stateMachine->approve($task, $this->manager->id, 'user', true);

        $this->assertEquals(TaskStateMachine::STATUS_ASSIGNED, $approvedTask->status);
        $this->assertNotNull($approvedTask->approved_at);
        $this->assertEquals($this->manager->id, $approvedTask->approved_by);

        $events = TaskEvent::where('task_id', $task->id)->get();
        $eventTypes = $events->pluck('event_type')->toArray();

        $this->assertContains('APPROVED', $eventTypes);
        $this->assertContains('ASSIGNED', $eventTypes);
    }

    public function test_cannot_approve_task_with_ambiguous_owner(): void
    {
        $task = $this->createTask([
            'status' => TaskStateMachine::STATUS_PENDING_APPROVAL,
            'owner_id' => null,
            'owner_ambiguous' => true,
            'owner_name_raw' => 'Ali',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Cannot approve a task with an unresolved owner.');

        try {
            $this->stateMachine->approve($task, $this->manager->id, 'user');
        } catch (ApiException $e) {
            $this->assertEquals('OWNER_REQUIRED', $e->getErrorCode());
            $this->assertEquals(422, $e->getStatusCode());
            $this->assertEquals('owner_id', $e->getField());
            throw $e;
        }
    }

    public function test_cannot_approve_task_with_null_owner_id(): void
    {
        $task = $this->createTask([
            'status' => TaskStateMachine::STATUS_PENDING_APPROVAL,
            'owner_id' => null,
            'owner_ambiguous' => false,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Cannot approve a task with an unresolved owner.');

        try {
            $this->stateMachine->transition($task, TaskStateMachine::STATUS_APPROVED, $this->manager->id, 'user');
        } catch (ApiException $e) {
            $this->assertEquals('OWNER_REQUIRED', $e->getErrorCode());
            $this->assertEquals(422, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_rejection_from_pending_approval_moves_to_terminal_rejected(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_PENDING_APPROVAL]);

        $task = $this->stateMachine->reject($task, $this->manager->id, 'user', 'Not actionable');
        $this->assertEquals(TaskStateMachine::STATUS_REJECTED, $task->status);

        $event = TaskEvent::where('task_id', $task->id)->where('event_type', 'REJECTED')->first();
        $this->assertNotNull($event);
        $this->assertEquals('Not actionable', $event->metadata['reason'] ?? null);
    }

    public function test_block_and_unblock_restores_previous_status(): void
    {
        // 1. Block from in_progress
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_IN_PROGRESS]);

        $blockedTask = $this->stateMachine->block(
            $task,
            $this->user->id,
            'user',
            'waiting_for_person',
            'Waiting for Ahmed to reply'
        );

        $this->assertEquals(TaskStateMachine::STATUS_BLOCKED, $blockedTask->status);
        $this->assertEquals(TaskStateMachine::STATUS_IN_PROGRESS, $blockedTask->previous_status);

        // Verify task_blockers created
        $blocker = TaskBlocker::where('task_id', $task->id)->first();
        $this->assertNotNull($blocker);
        $this->assertEquals('waiting_for_person', $blocker->reason_code);
        $this->assertNull($blocker->resolved_at);

        // 2. Unblock restores to in_progress
        $unblockedTask = $this->stateMachine->unblock($blockedTask, $this->manager->id, 'user');
        $this->assertEquals(TaskStateMachine::STATUS_IN_PROGRESS, $unblockedTask->status);
        $this->assertNull($unblockedTask->previous_status);

        // Verify blocker resolved
        $blocker->refresh();
        $this->assertNotNull($blocker->resolved_at);
        $this->assertEquals($this->manager->id, $blocker->resolved_by);

        // Verify events logged
        $eventTypes = TaskEvent::where('task_id', $task->id)->pluck('event_type')->toArray();
        $this->assertContains('BLOCKED', $eventTypes);
        $this->assertContains('UNBLOCKED', $eventTypes);
    }

    public function test_block_and_unblock_from_assigned_status(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_ASSIGNED]);

        $task = $this->stateMachine->block(
            $task,
            $this->user->id,
            'user',
            'technical_issue',
            'Server offline'
        );

        $this->assertEquals(TaskStateMachine::STATUS_BLOCKED, $task->status);
        $this->assertEquals(TaskStateMachine::STATUS_ASSIGNED, $task->previous_status);

        $task = $this->stateMachine->unblock($task, $this->manager->id, 'user');
        $this->assertEquals(TaskStateMachine::STATUS_ASSIGNED, $task->status);
        $this->assertNull($task->previous_status);
    }

    public function test_block_with_task_dependency_creates_dependency_and_resolves_it(): void
    {
        $depTask = $this->createTask(['status' => TaskStateMachine::STATUS_IN_PROGRESS]);
        $mainTask = $this->createTask(['status' => TaskStateMachine::STATUS_IN_PROGRESS]);

        $this->stateMachine->block(
            $mainTask,
            $this->user->id,
            'user',
            'waiting_for_person',
            'Waiting for Task #'.$depTask->id,
            $depTask->id
        );

        $dependency = TaskDependency::where('task_id', $mainTask->id)
            ->where('depends_on_task_id', $depTask->id)
            ->first();

        $this->assertNotNull($dependency);
        $this->assertEquals('active', $dependency->status);

        // Unblock
        $this->stateMachine->unblock($mainTask, $this->manager->id, 'user');
        $dependency->refresh();
        $this->assertEquals('resolved', $dependency->status);
    }

    public function test_illegal_transition_pending_approval_to_completed_throws(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_PENDING_APPROVAL]);

        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessage("Cannot transition task from 'pending_approval' to 'completed'. Task must be approved first.");

        $this->stateMachine->transition($task, TaskStateMachine::STATUS_COMPLETED);
    }

    public function test_illegal_transition_completed_to_in_progress_throws(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_COMPLETED]);

        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessage("Cannot transition task from 'completed'. Completed is a terminal state.");

        $this->stateMachine->transition($task, TaskStateMachine::STATUS_IN_PROGRESS);
    }

    public function test_illegal_transition_completed_to_anything_throws(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_COMPLETED]);

        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessage("Cannot transition task from 'completed'. Completed is a terminal state.");

        $this->stateMachine->transition($task, TaskStateMachine::STATUS_BLOCKED);
    }

    public function test_illegal_transition_rejected_to_anything_throws(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_REJECTED]);

        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessage("Cannot transition task from 'rejected'. Rejected is a terminal state.");

        $this->stateMachine->transition($task, TaskStateMachine::STATUS_IN_PROGRESS);
    }

    public function test_illegal_transition_detected_to_completed_throws(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_DETECTED]);

        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessage("Illegal state transition from 'detected' to 'completed'.");

        $this->stateMachine->transition($task, TaskStateMachine::STATUS_COMPLETED);
    }

    public function test_cannot_block_already_blocked_task(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_BLOCKED]);

        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessage('Task is already blocked.');

        $this->stateMachine->block($task);
    }

    public function test_cannot_unblock_non_blocked_task(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_IN_PROGRESS]);

        $this->expectException(InvalidStateTransitionException::class);
        $this->expectExceptionMessage('Cannot unblock a task that is not currently blocked.');

        $this->stateMachine->unblock($task);
    }

    public function test_overdue_and_escalated_transitions(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_IN_PROGRESS]);

        // Mark overdue
        $task = $this->stateMachine->markOverdue($task);
        $this->assertEquals(TaskStateMachine::STATUS_OVERDUE, $task->status);

        $overdueEvent = TaskEvent::where('task_id', $task->id)->where('event_type', 'OVERDUE')->first();
        $this->assertNotNull($overdueEvent);

        // Escalate
        $task = $this->stateMachine->escalate($task, 2);
        $this->assertEquals(TaskStateMachine::STATUS_ESCALATED, $task->status);
        $this->assertEquals(2, $task->escalation_level);

        $escalatedEvent = TaskEvent::where('task_id', $task->id)->where('event_type', 'ESCALATED')->first();
        $this->assertNotNull($escalatedEvent);
        $this->assertEquals(2, $escalatedEvent->metadata['escalation_level']);

        // Complete from escalated
        $task = $this->stateMachine->complete($task, $this->user->id, 'user');
        $this->assertEquals(TaskStateMachine::STATUS_COMPLETED, $task->status);
    }

    public function test_task_events_audit_log_is_append_only(): void
    {
        $task = $this->createTask(['status' => TaskStateMachine::STATUS_PENDING_APPROVAL]);

        $initialEventCount = TaskEvent::where('task_id', $task->id)->count();

        $this->stateMachine->approve($task, $this->manager->id, 'user', true);
        $this->stateMachine->start($task, $this->user->id, 'user');
        $this->stateMachine->block($task, $this->user->id, 'user', 'waiting_for_info');
        $this->stateMachine->unblock($task, $this->manager->id, 'user');
        $this->stateMachine->complete($task, $this->user->id, 'user');

        $finalEvents = TaskEvent::where('task_id', $task->id)->orderBy('id', 'asc')->get();

        $this->assertGreaterThan($initialEventCount, $finalEvents->count());
        $eventTypes = $finalEvents->pluck('event_type')->toArray();

        $expectedSequence = ['APPROVED', 'ASSIGNED', 'STARTED', 'BLOCKED', 'UNBLOCKED', 'COMPLETED'];
        foreach ($expectedSequence as $expectedEvent) {
            $this->assertContains($expectedEvent, $eventTypes);
        }
    }
}
