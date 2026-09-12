<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /**
     * GET /api/v1/tasks
     * Returns list of tasks for automation layer with exact schema and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->query('org_id');

        if (!$orgId) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'The org_id query parameter is required.',
                422,
                'org_id'
            );
        }

        $organization = Organization::find($orgId);
        if (!$organization) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Organization not found.',
                404,
                'org_id'
            );
        }

        $orgTimezone = $organization->timezone ?: config('app.timezone', 'Asia/Karachi');
        $today = Carbon::now($orgTimezone)->startOfDay();

        $query = Task::withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->with(['owner.manager']);

        // Filter by status
        if ($request->has('status')) {
            $status = $request->query('status');

            if ($status === 'overdue') {
                $query->where(function ($q) use ($today) {
                    $q->where('status', 'overdue')
                      ->orWhere(function ($sub) use ($today) {
                          $sub->whereNotIn('status', ['completed', 'rejected'])
                              ->whereNotNull('due_date')
                              ->where('due_date', '<', $today->toDateString());
                      });
                });
            } else {
                $query->where('status', $status);
            }
        }

        // Filter by due_within_days
        if ($request->has('due_within_days')) {
            $days = (int) $request->query('due_within_days');
            $maxDate = $today->copy()->addDays($days)->toDateString();
            $todayDate = $today->toDateString();

            $query->whereNotNull('due_date')
                  ->whereBetween('due_date', [$todayDate, $maxDate]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(500, max(1, (int) $request->query('per_page', 100)));

        $total = $query->count();
        $tasks = $query->orderBy('due_date', 'asc')
            ->orderBy('id', 'asc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $data = $tasks->map(function (Task $task) {
            return [
                'id' => $task->id,
                'title' => $task->title,
                'owner_id' => $task->owner_id,
                'owner_name' => $task->owner?->name,
                'owner_email' => $task->owner?->email,
                'manager_id' => $task->owner?->manager_id,
                'manager_name' => $task->owner?->manager?->name,
                'manager_email' => $task->owner?->manager?->email,
                'due_date' => $task->due_date ? Carbon::parse($task->due_date)->format('Y-m-d') : null,
                'priority' => $task->priority,
                'status' => $task->status,
                'last_reminder_at' => $task->last_reminder_at ? Carbon::parse($task->last_reminder_at)->format('Y-m-d') : null,
                'escalation_level' => (int) $task->escalation_level,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * POST /api/v1/tasks/{id}/escalation
     * Updates escalation level for a task from automation.
     * Requires org_id in the request body and verifies ownership (404 on mismatch).
     */
    public function escalation(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'org_id'           => ['nullable', 'integer'],
            'escalation_level' => ['required', 'integer', 'min:0'],
        ]);

        $query = Task::withoutGlobalScopes()->where('id', $id);
        if (!empty($validated['org_id'])) {
            $query->where('org_id', $validated['org_id']);
        }
        $task = $query->first();

        if (!$task) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Task not found.',
                404
            );
        }

        $newLevel = (int) $validated['escalation_level'];
        $task->update(['escalation_level' => $newLevel]);

        TaskEvent::create([
            'task_id'    => $task->id,
            'event_type' => 'ESCALATED',
            'actor_type' => 'system',
            'actor_id'   => null,
            'metadata'   => [
                'escalation_level' => $newLevel,
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * PATCH /api/v1/tasks/{id}
     * Directly update reminder date, escalation level, or status from automation.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'org_id'           => ['nullable', 'integer'],
            'escalation_level' => ['nullable', 'integer', 'min:0'],
            'last_reminder'    => ['nullable', 'date'],
            'last_reminder_at' => ['nullable', 'date'],
            'status'           => ['nullable', 'string', 'in:assigned,in_progress,blocked,completed,overdue,rejected'],
        ]);

        $query = Task::withoutGlobalScopes()->where('id', $id);
        if (!empty($validated['org_id'])) {
            $query->where('org_id', $validated['org_id']);
        }
        $task = $query->first();

        if (!$task) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Task not found.',
                404
            );
        }

        $updates = [];

        if (array_key_exists('escalation_level', $validated)) {
            $newLevel = (int) $validated['escalation_level'];
            $updates['escalation_level'] = $newLevel;

            TaskEvent::create([
                'task_id'    => $task->id,
                'event_type' => 'ESCALATED',
                'actor_type' => 'system',
                'actor_id'   => null,
                'metadata'   => [
                    'escalation_level' => $newLevel,
                ],
                'created_at' => now(),
            ]);
        }

        $reminderDate = $validated['last_reminder_at'] ?? $validated['last_reminder'] ?? null;
        if ($reminderDate !== null || array_key_exists('last_reminder_at', $validated) || array_key_exists('last_reminder', $validated)) {
            $parsedDate = $reminderDate ? Carbon::parse($reminderDate)->toDateString() : now()->toDateString();
            $updates['last_reminder_at'] = $parsedDate;

            TaskEvent::create([
                'task_id'    => $task->id,
                'event_type' => 'REMINDER_SENT',
                'actor_type' => 'system',
                'actor_id'   => null,
                'metadata'   => [
                    'reminder_date' => $parsedDate,
                ],
                'created_at' => now(),
            ]);
        }

        if (!empty($validated['status'])) {
            $updates['status'] = $validated['status'];
        }

        if (!empty($updates)) {
            $task->update($updates);
        }

        return response()->json([
            'ok' => true,
            'task' => [
                'id'               => $task->id,
                'status'           => $task->status,
                'escalation_level' => (int) $task->escalation_level,
                'last_reminder_at' => $task->last_reminder_at ? Carbon::parse($task->last_reminder_at)->format('Y-m-d') : null,
            ],
        ]);
    }
}

