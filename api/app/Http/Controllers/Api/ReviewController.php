<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskStateMachine;
use App\Services\WebhookService;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    /**
     * GET /api/meetings/{id} or /api/meetings/{id}/review
     * Returns meeting details, extracted tasks with owner relations, org users roster, and statistics.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $meeting = Meeting::withoutGlobalScopes()
            ->with(['creator', 'organization'])
            ->find($id);

        if (!$meeting || $meeting->org_id !== $user->org_id) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Meeting not found.',
                404
            );
        }

        if (!$user->can('review', $meeting)) {
            return ApiResponse::error(
                'FORBIDDEN',
                'You do not have permission to review this meeting.',
                403
            );
        }

        $tasks = Task::withoutGlobalScopes()
            ->where('meeting_id', $id)
            ->with(['owner'])
            ->orderBy('id', 'asc')
            ->get();

        $users = User::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->where('status', 'active')
            ->select('id', 'name', 'email', 'role', 'manager_id')
            ->orderBy('name', 'asc')
            ->get();

        $stats = [
            'total' => $tasks->count(),
            'pending' => $tasks->whereIn('status', ['pending_approval', 'detected'])->count(),
            'approved' => $tasks->whereIn('status', ['approved', 'assigned', 'in_progress', 'completed'])->count(),
            'rejected' => $tasks->where('status', 'rejected')->count(),
            'ambiguous' => $tasks->where('owner_ambiguous', true)->count(),
        ];

        return response()->json([
            'meeting' => [
                'id' => $meeting->id,
                'org_id' => $meeting->org_id,
                'title' => $meeting->title,
                'meeting_date' => $meeting->meeting_date ? Carbon::parse($meeting->meeting_date)->format('Y-m-d') : null,
                'timezone' => $meeting->timezone,
                'source' => $meeting->source,
                'status' => $meeting->status,
                'error_message' => $meeting->error_message,
                'transcript' => $meeting->transcript,
                'summary' => $meeting->summary,
                'created_by' => $meeting->created_by,
                'creator' => $meeting->creator ? [
                    'id' => $meeting->creator->id,
                    'name' => $meeting->creator->name,
                    'email' => $meeting->creator->email,
                ] : null,
                'created_at' => $meeting->created_at?->toIso8601String(),
            ],
            'tasks' => $tasks->map(fn (Task $t) => $this->formatTask($t)),
            'users' => $users,
            'stats' => $stats,
        ]);
    }

    /**
     * PATCH /api/tasks/{id}
     * Inline editing of a task's title, description, owner, due_date, priority, conditional.
     * Note: editing title/description does NOT silently clear owner_ambiguous.
     * It is only cleared when a valid owner is explicitly assigned from dropdown.
     */
    public function updateTask(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $task = Task::withoutGlobalScopes()->with(['meeting', 'owner'])->find($id);

        if (!$task || $task->org_id !== $user->org_id) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Task not found.',
                404
            );
        }

        if (!$user->can('update', $task) && !$user->can('approve', $task)) {
            return ApiResponse::error(
                'FORBIDDEN',
                'You do not have permission to edit this task.',
                403
            );
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_id' => ['nullable'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'priority' => ['nullable', 'string', 'in:high,medium,low'],
            'conditional' => ['nullable', 'boolean'],
            'owner_ambiguous' => ['nullable', 'boolean'],
        ]);

        $changes = [];

        if ($request->has('title')) {
            $task->title = $validated['title'] ?? $task->title;
            $changes['title'] = $task->title;
        }

        if ($request->has('description')) {
            $task->description = $validated['description'];
            $changes['description'] = $task->description;
        }

        if ($request->has('due_date')) {
            $task->due_date = $validated['due_date'];
            $changes['due_date'] = $task->due_date;
        }

        if ($request->has('priority')) {
            $task->priority = $validated['priority'];
            $changes['priority'] = $task->priority;
        }

        if ($request->has('conditional')) {
            $task->conditional = (bool) $validated['conditional'];
            $changes['conditional'] = $task->conditional;
        }

        // Owner assignment logic
        if ($request->has('owner_id')) {
            $rawOwnerId = $request->input('owner_id');

            if ($rawOwnerId !== null && $rawOwnerId !== '') {
                $cleanedId = (int) preg_replace('/[^0-9]/', '', (string) $rawOwnerId);
                $ownerUser = User::withoutGlobalScopes()
                    ->where('org_id', $user->org_id)
                    ->find($cleanedId);

                if ($ownerUser) {
                    $task->owner_id = $ownerUser->id;
                    $task->owner_ambiguous = false;
                    $changes['owner_id'] = $ownerUser->id;
                    $changes['owner_ambiguous'] = false;
                }
            } else {
                // If explicitly set to null/empty
                $task->owner_id = null;
                $changes['owner_id'] = null;
            }
        } elseif ($request->has('owner_ambiguous')) {
            $task->owner_ambiguous = (bool) $validated['owner_ambiguous'];
            $changes['owner_ambiguous'] = $task->owner_ambiguous;
        }

        $task->save();

        // Record EDITED event in audit log
        TaskEvent::create([
            'task_id' => $task->id,
            'event_type' => 'EDITED',
            'actor_type' => 'user',
            'actor_id' => $user->id,
            'metadata' => [
                'changes' => $changes,
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'task' => $this->formatTask($task->fresh(['owner'])),
        ]);
    }

    /**
     * POST /api/tasks/{id}/approve
     * Approves a single task card. Disabled and rejected if owner is ambiguous or missing.
     * When the last card for the meeting is resolved, updates meeting status to 'reviewed'
     * and dispatches the tasks.approved outbound webhook.
     */
    public function approveTask(
        Request $request,
        int $id,
        TaskStateMachine $stateMachine,
        WebhookService $webhookService
    ): JsonResponse {
        $user = $request->user();

        $task = Task::withoutGlobalScopes()->with(['meeting', 'owner'])->find($id);

        if (!$task || $task->org_id !== $user->org_id) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Task not found.',
                404
            );
        }

        if (!$user->can('approve', $task)) {
            return ApiResponse::error(
                'FORBIDDEN',
                'You do not have permission to approve tasks for this meeting.',
                403
            );
        }

        // Idempotent safety check: if already approved/assigned
        if (in_array($task->status, [TaskStateMachine::STATUS_APPROVED, TaskStateMachine::STATUS_ASSIGNED], true)) {
            return response()->json([
                'ok' => true,
                'task' => $this->formatTask($task),
                'meeting_status' => $task->meeting?->status,
                'already_approved' => true,
            ]);
        }

        // Owner required verification
        if ($task->owner_ambiguous || empty($task->owner_id)) {
            return ApiResponse::error(
                'OWNER_REQUIRED',
                'Cannot approve a task with an unresolved owner.',
                422,
                'owner_id'
            );
        }

        $meetingId = $task->meeting_id;

        DB::transaction(function () use ($task, $user, $stateMachine, $webhookService, $meetingId) {
            $stateMachine->approve($task, $user->id, 'user');

            // Check if all tasks in the meeting are now resolved
            if ($meetingId) {
                $this->checkAndResolveMeeting($meetingId, $webhookService);
            }
        });

        $updatedTask = $task->fresh(['owner']);
        $meeting = $meetingId ? Meeting::withoutGlobalScopes()->find($meetingId) : null;

        return response()->json([
            'ok' => true,
            'task' => $this->formatTask($updatedTask),
            'meeting_status' => $meeting?->status,
            'is_meeting_resolved' => $meeting?->status === 'reviewed',
        ]);
    }

    /**
     * POST /api/tasks/{id}/reject
     * Rejects a single task card.
     */
    public function rejectTask(
        Request $request,
        int $id,
        TaskStateMachine $stateMachine,
        WebhookService $webhookService
    ): JsonResponse {
        $user = $request->user();

        $task = Task::withoutGlobalScopes()->with(['meeting', 'owner'])->find($id);

        if (!$task || $task->org_id !== $user->org_id) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Task not found.',
                404
            );
        }

        if (!$user->can('reject', $task)) {
            return ApiResponse::error(
                'FORBIDDEN',
                'You do not have permission to reject tasks for this meeting.',
                403
            );
        }

        if ($task->status === TaskStateMachine::STATUS_REJECTED) {
            return response()->json([
                'ok' => true,
                'task' => $this->formatTask($task),
                'already_rejected' => true,
            ]);
        }

        $reason = $request->input('reason');
        $meetingId = $task->meeting_id;

        DB::transaction(function () use ($task, $user, $reason, $stateMachine, $webhookService, $meetingId) {
            $stateMachine->reject($task, $user->id, 'user', $reason);

            if ($meetingId) {
                $this->checkAndResolveMeeting($meetingId, $webhookService);
            }
        });

        $updatedTask = $task->fresh(['owner']);
        $meeting = $meetingId ? Meeting::withoutGlobalScopes()->find($meetingId) : null;

        return response()->json([
            'ok' => true,
            'task' => $this->formatTask($updatedTask),
            'meeting_status' => $meeting?->status,
            'is_meeting_resolved' => $meeting?->status === 'reviewed',
        ]);
    }

    /**
     * POST /api/meetings/{id}/approve-all
     * Approves all pending tasks in the meeting while cleanly skipping any cards
     * missing a required owner or with owner_ambiguous=true. Safe against double-clicks.
     */
    public function approveAll(
        Request $request,
        int $id,
        TaskStateMachine $stateMachine,
        WebhookService $webhookService
    ): JsonResponse {
        $user = $request->user();

        $meeting = Meeting::withoutGlobalScopes()->find($id);

        if (!$meeting || $meeting->org_id !== $user->org_id) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Meeting not found.',
                404
            );
        }

        if (!$user->can('review', $meeting)) {
            return ApiResponse::error(
                'FORBIDDEN',
                'You do not have permission to review this meeting.',
                403
            );
        }

        $approvedCount = 0;
        $skippedCount = 0;

        DB::transaction(function () use ($meeting, $user, $stateMachine, $webhookService, &$approvedCount, &$skippedCount) {
            // Find all pending tasks
            $pendingTasks = Task::withoutGlobalScopes()
                ->where('meeting_id', $meeting->id)
                ->whereIn('status', [TaskStateMachine::STATUS_PENDING_APPROVAL, TaskStateMachine::STATUS_DETECTED])
                ->get();

            foreach ($pendingTasks as $task) {
                // Skip cards missing an owner or marked ambiguous
                if ($task->owner_ambiguous || empty($task->owner_id)) {
                    $skippedCount++;
                    continue;
                }

                // Approve eligible task
                $stateMachine->approve($task, $user->id, 'user');
                $approvedCount++;
            }

            // Check if meeting is fully resolved
            $this->checkAndResolveMeeting($meeting->id, $webhookService);
        });

        $allTasks = Task::withoutGlobalScopes()
            ->where('meeting_id', $meeting->id)
            ->with('owner')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'ok' => true,
            'approved_count' => $approvedCount,
            'skipped_count' => $skippedCount,
            'meeting_status' => $meeting->fresh()->status,
            'tasks' => $allTasks->map(fn (Task $t) => $this->formatTask($t)),
        ]);
    }

    /**
     * Checks if all tasks for a meeting are resolved.
     * If 0 pending remain and meeting is not yet 'reviewed', marks it reviewed
     * and dispatches the outbound tasks.approved webhook.
     */
    protected function checkAndResolveMeeting(int $meetingId, WebhookService $webhookService): void
    {
        $remainingPending = Task::withoutGlobalScopes()
            ->where('meeting_id', $meetingId)
            ->whereIn('status', [TaskStateMachine::STATUS_PENDING_APPROVAL, TaskStateMachine::STATUS_DETECTED])
            ->count();

        if ($remainingPending === 0) {
            $meeting = Meeting::withoutGlobalScopes()->find($meetingId);

            if ($meeting && $meeting->status !== 'reviewed') {
                $meeting->update(['status' => 'reviewed']);

                // Gather all approved tasks for this meeting payload
                $approvedTasks = Task::withoutGlobalScopes()
                    ->where('meeting_id', $meeting->id)
                    ->whereIn('status', [
                        TaskStateMachine::STATUS_APPROVED,
                        TaskStateMachine::STATUS_ASSIGNED,
                        TaskStateMachine::STATUS_IN_PROGRESS,
                        TaskStateMachine::STATUS_COMPLETED,
                    ])
                    ->with('owner')
                    ->get()
                    ->map(fn (Task $t) => [
                        'id' => $t->id,
                        'title' => $t->title,
                        'owner_id' => $t->owner_id,
                        'owner_name' => $t->owner?->name,
                        'owner_email' => $t->owner?->email,
                        'due_date' => $t->due_date ? Carbon::parse($t->due_date)->format('Y-m-d') : null,
                        'priority' => $t->priority,
                    ])
                    ->toArray();

                if (!empty($approvedTasks)) {
                    $webhookService->dispatchTasksApproved(
                        $meeting->org_id,
                        $meeting->id,
                        $approvedTasks
                    );
                }
            }
        }
    }

    /**
     * Format a task model with all relevant review fields.
     */
    protected function formatTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'org_id' => $task->org_id,
            'meeting_id' => $task->meeting_id,
            'title' => $task->title,
            'description' => $task->description,
            'owner_id' => $task->owner_id,
            'owner_name_raw' => $task->owner_name_raw,
            'owner_ambiguous' => (bool) $task->owner_ambiguous,
            'owner' => $task->owner ? [
                'id' => $task->owner->id,
                'name' => $task->owner->name,
                'email' => $task->owner->email,
                'role' => $task->owner->role,
            ] : null,
            'created_by' => $task->created_by,
            'approved_by' => $task->approved_by,
            'priority' => $task->priority,
            'status' => $task->status,
            'due_date' => $task->due_date ? Carbon::parse($task->due_date)->format('Y-m-d') : null,
            'deadline_phrase' => $task->deadline_phrase,
            'source_text' => $task->source_text,
            'conditional' => (bool) $task->conditional,
            'owner_confidence' => $task->owner_confidence !== null ? (float) $task->owner_confidence : null,
            'deadline_confidence' => $task->deadline_confidence !== null ? (float) $task->deadline_confidence : null,
            'action_confidence' => $task->action_confidence !== null ? (float) $task->action_confidence : null,
            'last_reminder_at' => $task->last_reminder_at ? Carbon::parse($task->last_reminder_at)->format('Y-m-d') : null,
            'escalation_level' => (int) $task->escalation_level,
            'approved_at' => $task->approved_at?->toIso8601String(),
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }
}
