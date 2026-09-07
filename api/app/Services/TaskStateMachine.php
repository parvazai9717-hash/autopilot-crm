<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Task;
use App\Models\TaskBlocker;
use App\Models\TaskDependency;
use App\Models\TaskEvent;
use Illuminate\Support\Facades\DB;

class TaskStateMachine
{
    public const STATUS_DETECTED = 'detected';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_ESCALATED = 'escalated';

    /**
     * All recognized valid statuses.
     */
    public const ALL_STATUSES = [
        self::STATUS_DETECTED,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_BLOCKED,
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
        self::STATUS_OVERDUE,
        self::STATUS_ESCALATED,
    ];

    /**
     * Legal transitions allowed from each state.
     */
    protected static array $transitions = [
        self::STATUS_DETECTED => [
            self::STATUS_PENDING_APPROVAL,
            self::STATUS_REJECTED,
        ],
        self::STATUS_PENDING_APPROVAL => [
            self::STATUS_APPROVED,
            self::STATUS_ASSIGNED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_APPROVED => [
            self::STATUS_ASSIGNED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_BLOCKED,
            self::STATUS_OVERDUE,
            self::STATUS_ESCALATED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_ASSIGNED => [
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_BLOCKED,
            self::STATUS_OVERDUE,
            self::STATUS_ESCALATED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_IN_PROGRESS => [
            self::STATUS_COMPLETED,
            self::STATUS_BLOCKED,
            self::STATUS_OVERDUE,
            self::STATUS_ESCALATED,
            self::STATUS_ASSIGNED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_BLOCKED => [
            // Blocked state can unblock back to previous states or be rejected
            self::STATUS_IN_PROGRESS,
            self::STATUS_ASSIGNED,
            self::STATUS_APPROVED,
            self::STATUS_OVERDUE,
            self::STATUS_ESCALATED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_OVERDUE => [
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_BLOCKED,
            self::STATUS_ESCALATED,
            self::STATUS_ASSIGNED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_ESCALATED => [
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_BLOCKED,
            self::STATUS_OVERDUE,
            self::STATUS_ASSIGNED,
            self::STATUS_REJECTED,
        ],
        self::STATUS_COMPLETED => [], // Terminal state
        self::STATUS_REJECTED => [],  // Terminal state
    ];

    /**
     * Map target statuses to default event types.
     */
    protected static array $eventMap = [
        self::STATUS_APPROVED => 'APPROVED',
        self::STATUS_ASSIGNED => 'ASSIGNED',
        self::STATUS_IN_PROGRESS => 'STARTED',
        self::STATUS_COMPLETED => 'COMPLETED',
        self::STATUS_BLOCKED => 'BLOCKED',
        self::STATUS_REJECTED => 'REJECTED',
        self::STATUS_OVERDUE => 'OVERDUE',
        self::STATUS_ESCALATED => 'ESCALATED',
        self::STATUS_PENDING_APPROVAL => 'APPROVAL_REQUESTED',
    ];

    /**
     * Check if a transition from current status to target status is valid.
     */
    public function canTransition(Task $task, string $targetStatus): bool
    {
        $currentStatus = $task->status;

        if (!in_array($targetStatus, self::ALL_STATUSES, true)) {
            return false;
        }

        if ($currentStatus === self::STATUS_COMPLETED || $currentStatus === self::STATUS_REJECTED) {
            return false;
        }

        if ($currentStatus === $targetStatus) {
            return false;
        }

        $allowed = self::$transitions[$currentStatus] ?? [];

        return in_array($targetStatus, $allowed, true);
    }

    /**
     * Strictly validate the transition and throw specific descriptive exceptions on failure.
     *
     * @throws InvalidStateTransitionException|ApiException
     */
    public function validateTransition(Task $task, string $targetStatus): void
    {
        $currentStatus = $task->status;

        if (!in_array($targetStatus, self::ALL_STATUSES, true)) {
            throw new InvalidStateTransitionException(
                "Unknown target status '{$targetStatus}'."
            );
        }

        if ($currentStatus === self::STATUS_COMPLETED) {
            throw new InvalidStateTransitionException(
                "Cannot transition task from 'completed'. Completed is a terminal state."
            );
        }

        if ($currentStatus === self::STATUS_REJECTED) {
            throw new InvalidStateTransitionException(
                "Cannot transition task from 'rejected'. Rejected is a terminal state."
            );
        }

        if ($currentStatus === $targetStatus) {
            throw new InvalidStateTransitionException(
                "Task is already in status '{$targetStatus}'."
            );
        }

        if ($currentStatus === self::STATUS_PENDING_APPROVAL && $targetStatus === self::STATUS_COMPLETED) {
            throw new InvalidStateTransitionException(
                "Cannot transition task from 'pending_approval' to 'completed'. Task must be approved first."
            );
        }

        // Section 13 & 16: Owner required check when approving from pending_approval
        if (
            in_array($targetStatus, [self::STATUS_APPROVED, self::STATUS_ASSIGNED], true) &&
            $currentStatus === self::STATUS_PENDING_APPROVAL
        ) {
            if ($task->owner_ambiguous || empty($task->owner_id)) {
                throw new ApiException(
                    'Cannot approve a task with an unresolved owner.',
                    'OWNER_REQUIRED',
                    422,
                    'owner_id'
                );
            }
        }

        $allowed = self::$transitions[$currentStatus] ?? [];
        if (!in_array($targetStatus, $allowed, true)) {
            throw new InvalidStateTransitionException(
                "Illegal state transition from '{$currentStatus}' to '{$targetStatus}'."
            );
        }
    }

    /**
     * Perform the transition on the Task model with audit logging and timestamp updates.
     *
     * @throws InvalidStateTransitionException|ApiException
     */
    public function transition(
        Task $task,
        string $targetStatus,
        ?int $actorId = null,
        string $actorType = 'system',
        array $metadata = []
    ): Task {
        $this->validateTransition($task, $targetStatus);

        return DB::transaction(function () use ($task, $targetStatus, $actorId, $actorType, $metadata) {
            $previousStatus = $task->status;

            // Handle previous_status for blocked state
            if ($targetStatus === self::STATUS_BLOCKED) {
                $task->previous_status = $previousStatus;
            } elseif ($previousStatus === self::STATUS_BLOCKED) {
                // Clear previous_status once restored/unblocked
                $task->previous_status = null;
            }

            $task->status = $targetStatus;

            // Update timestamps according to target status
            if ($targetStatus === self::STATUS_APPROVED) {
                $task->approved_at = now();
                if ($actorType === 'user' && $actorId) {
                    $task->approved_by = $actorId;
                }
            } elseif ($targetStatus === self::STATUS_ASSIGNED && $previousStatus === self::STATUS_PENDING_APPROVAL) {
                $task->approved_at = $task->approved_at ?: now();
                if ($actorType === 'user' && $actorId) {
                    $task->approved_by = $task->approved_by ?: $actorId;
                }
            } elseif ($targetStatus === self::STATUS_IN_PROGRESS && !$task->started_at) {
                $task->started_at = now();
            } elseif ($targetStatus === self::STATUS_COMPLETED) {
                $task->completed_at = now();
            }

            $task->save();

            // Record audit event
            $eventType = self::$eventMap[$targetStatus] ?? strtoupper($targetStatus);

            TaskEvent::create([
                'task_id' => $task->id,
                'event_type' => $eventType,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'metadata' => !empty($metadata) ? $metadata : null,
                'created_at' => now(),
            ]);

            return $task;
        });
    }

    /**
     * Approve a task (transitions to approved, and optionally immediately to assigned).
     */
    public function approve(
        Task $task,
        ?int $actorId = null,
        string $actorType = 'user',
        bool $autoAssign = true
    ): Task {
        if ($autoAssign && !empty($task->owner_id)) {
            // First perform approval validations
            $this->validateTransition($task, self::STATUS_APPROVED);

            return DB::transaction(function () use ($task, $actorId, $actorType) {
                $task->approved_at = now();
                if ($actorType === 'user' && $actorId) {
                    $task->approved_by = $actorId;
                }
                $task->status = self::STATUS_ASSIGNED;
                $task->save();

                // Section 16: write APPROVED and ASSIGNED task_events
                TaskEvent::create([
                    'task_id' => $task->id,
                    'event_type' => 'APPROVED',
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'metadata' => null,
                    'created_at' => now(),
                ]);

                TaskEvent::create([
                    'task_id' => $task->id,
                    'event_type' => 'ASSIGNED',
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'metadata' => ['owner_id' => $task->owner_id],
                    'created_at' => now(),
                ]);

                return $task;
            });
        }

        return $this->transition($task, self::STATUS_APPROVED, $actorId, $actorType);
    }

    /**
     * Reject a task (moves to terminal rejected status).
     */
    public function reject(
        Task $task,
        ?int $actorId = null,
        string $actorType = 'user',
        ?string $reason = null
    ): Task {
        $metadata = [];
        if ($reason) {
            $metadata['reason'] = $reason;
        }

        return $this->transition($task, self::STATUS_REJECTED, $actorId, $actorType, $metadata);
    }

    /**
     * Start work on a task (transitions to in_progress).
     */
    public function start(
        Task $task,
        ?int $actorId = null,
        string $actorType = 'user'
    ): Task {
        return $this->transition($task, self::STATUS_IN_PROGRESS, $actorId, $actorType);
    }

    /**
     * Complete a task (moves to terminal completed status).
     */
    public function complete(
        Task $task,
        ?int $actorId = null,
        string $actorType = 'user'
    ): Task {
        return $this->transition($task, self::STATUS_COMPLETED, $actorId, $actorType);
    }

    /**
     * Block a task (saves previous_status, creates blocker record & event).
     */
    public function block(
        Task $task,
        ?int $actorId = null,
        string $actorType = 'user',
        ?string $reasonCode = null,
        ?string $description = null,
        ?int $dependsOnTaskId = null
    ): Task {
        if ($task->status === self::STATUS_BLOCKED) {
            throw new InvalidStateTransitionException('Task is already blocked.');
        }

        $this->validateTransition($task, self::STATUS_BLOCKED);

        return DB::transaction(function () use (
            $task,
            $actorId,
            $actorType,
            $reasonCode,
            $description,
            $dependsOnTaskId
        ) {
            $previousStatus = $task->status;
            $task->previous_status = $previousStatus;
            $task->status = self::STATUS_BLOCKED;
            $task->save();

            // Create task_blockers row
            TaskBlocker::create([
                'task_id' => $task->id,
                'reason_code' => $reasonCode ?: 'other',
                'description' => $description,
                'blocked_by' => $actorId,
                'depends_on_task_id' => $dependsOnTaskId,
                'created_at' => now(),
            ]);

            // If a dependent task was specified, record in task_dependencies
            if ($dependsOnTaskId) {
                TaskDependency::create([
                    'task_id' => $task->id,
                    'depends_on_task_id' => $dependsOnTaskId,
                    'dependency_type' => 'blocks',
                    'status' => 'active',
                    'created_by' => $actorId,
                    'created_at' => now(),
                ]);
            }

            // Create BLOCKED event
            TaskEvent::create([
                'task_id' => $task->id,
                'event_type' => 'BLOCKED',
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'metadata' => [
                    'previous_status' => $previousStatus,
                    'reason_code' => $reasonCode,
                    'description' => $description,
                    'depends_on_task_id' => $dependsOnTaskId,
                ],
                'created_at' => now(),
            ]);

            return $task;
        });
    }

    /**
     * Unblock a task (restores status to previous_status).
     */
    public function unblock(
        Task $task,
        ?int $actorId = null,
        string $actorType = 'user',
        ?string $resolvedBy = null
    ): Task {
        if ($task->status !== self::STATUS_BLOCKED) {
            throw new InvalidStateTransitionException('Cannot unblock a task that is not currently blocked.');
        }

        $restoreStatus = $task->previous_status ?: self::STATUS_IN_PROGRESS;

        return DB::transaction(function () use ($task, $restoreStatus, $actorId, $actorType, $resolvedBy) {
            $task->status = $restoreStatus;
            $task->previous_status = null;
            $task->save();

            // Resolve open blockers
            TaskBlocker::where('task_id', $task->id)
                ->whereNull('resolved_at')
                ->update([
                    'resolved_at' => now(),
                    'resolved_by' => $actorId,
                ]);

            // Resolve dependencies if applicable
            TaskDependency::where('task_id', $task->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'resolved',
                ]);

            // Record UNBLOCKED event
            TaskEvent::create([
                'task_id' => $task->id,
                'event_type' => 'UNBLOCKED',
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'metadata' => [
                    'restored_status' => $restoreStatus,
                    'resolved_by' => $resolvedBy,
                ],
                'created_at' => now(),
            ]);

            return $task;
        });
    }

    /**
     * Mark a task as overdue.
     */
    public function markOverdue(
        Task $task,
        ?int $actorId = null,
        string $actorType = 'system'
    ): Task {
        return $this->transition($task, self::STATUS_OVERDUE, $actorId, $actorType);
    }

    /**
     * Escalate a task.
     */
    public function escalate(
        Task $task,
        int $level = 1,
        ?int $actorId = null,
        string $actorType = 'system'
    ): Task {
        return DB::transaction(function () use ($task, $level, $actorId, $actorType) {
            $task->escalation_level = $level;
            $task->save();

            if ($this->canTransition($task, self::STATUS_ESCALATED)) {
                return $this->transition($task, self::STATUS_ESCALATED, $actorId, $actorType, [
                    'escalation_level' => $level,
                ]);
            }

            // If already escalated or in active state, record ESCALATED event
            TaskEvent::create([
                'task_id' => $task->id,
                'event_type' => 'ESCALATED',
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'metadata' => [
                    'escalation_level' => $level,
                ],
                'created_at' => now(),
            ]);

            return $task;
        });
    }

    /**
     * Assign a task to an owner.
     */
    public function assign(
        Task $task,
        int $ownerId,
        ?int $actorId = null,
        string $actorType = 'user'
    ): Task {
        return DB::transaction(function () use ($task, $ownerId, $actorId, $actorType) {
            $task->owner_id = $ownerId;
            $task->owner_ambiguous = false;
            $task->save();

            if ($task->status === self::STATUS_APPROVED) {
                return $this->transition($task, self::STATUS_ASSIGNED, $actorId, $actorType, [
                    'owner_id' => $ownerId,
                ]);
            }

            TaskEvent::create([
                'task_id' => $task->id,
                'event_type' => 'ASSIGNED',
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'metadata' => [
                    'owner_id' => $ownerId,
                ],
                'created_at' => now(),
            ]);

            return $task;
        });
    }
}
