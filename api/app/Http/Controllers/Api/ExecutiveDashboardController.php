<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBlocker;
use App\Models\TaskDependency;
use App\Services\TaskStateMachine;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutiveDashboardController extends Controller
{
    /**
     * Ensure the authenticated user has executive or admin privileges.
     */
    protected function authorizeExecutive(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!$user->isExecutive() && !$user->isAdmin()) {
            return ApiResponse::error('FORBIDDEN', 'Executive or admin access required.', 403);
        }
        return null;
    }

    /**
     * GET /api/executive/dashboard
     * Returns company-wide KPI counts and the three exception lists per Section 17 of SPEC.md.
     * Read-only across the organization.
     */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeExecutive($request)) {
            return $deny;
        }

        $user = $request->user();
        $org = Organization::withoutGlobalScopes()->find($user->org_id);
        $tz = $org?->timezone ?: config('app.timezone', 'Asia/Karachi');

        // Section 8 rule: Carbon::now($org->timezone)->startOfDay(), not UTC
        $today = Carbon::now($tz)->startOfDay();
        $todayStr = $today->format('Y-m-d');
        $todayUtc = $today->copy()->setTimezone('UTC');

        // ---------------------------------------------------------------------
        // 1. KPI COUNTS
        // ---------------------------------------------------------------------

        // Base active tasks (assigned, in_progress, blocked, overdue, escalated)
        $openTasksQuery = Task::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->whereNotIn('status', [
                TaskStateMachine::STATUS_DETECTED,
                TaskStateMachine::STATUS_PENDING_APPROVAL,
                TaskStateMachine::STATUS_REJECTED,
                TaskStateMachine::STATUS_COMPLETED,
            ]);

        $activeCount = (clone $openTasksQuery)->count();

        // Completed Today: status = completed and completed_at >= today (in org timezone)
        $completedTodayCount = Task::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->where('status', TaskStateMachine::STATUS_COMPLETED)
            ->where('completed_at', '>=', $todayUtc)
            ->count();

        // Overdue: open tasks where due_date < today
        $overdueCount = (clone $openTasksQuery)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $todayStr)
            ->count();

        // Blocked: tasks with status = blocked
        $blockedCount = (clone $openTasksQuery)
            ->where('status', TaskStateMachine::STATUS_BLOCKED)
            ->count();

        // ---------------------------------------------------------------------
        // 2. AT RISK COMPUTATION (Computed on read, never stored)
        // A task is at risk when it is not yet complete and it has an active
        // dependency on a task that is now overdue.
        // ---------------------------------------------------------------------
        $atRiskDependencies = TaskDependency::withoutGlobalScopes()
            ->where('task_dependencies.status', 'active')
            ->join('tasks as dependent_tasks', 'dependent_tasks.id', '=', 'task_dependencies.task_id')
            ->join('tasks as blocking_tasks', 'blocking_tasks.id', '=', 'task_dependencies.depends_on_task_id')
            ->where('dependent_tasks.org_id', $user->org_id)
            ->whereNotIn('dependent_tasks.status', [
                TaskStateMachine::STATUS_DETECTED,
                TaskStateMachine::STATUS_PENDING_APPROVAL,
                TaskStateMachine::STATUS_REJECTED,
                TaskStateMachine::STATUS_COMPLETED,
            ])
            ->whereNotIn('blocking_tasks.status', [
                TaskStateMachine::STATUS_COMPLETED,
                TaskStateMachine::STATUS_REJECTED,
            ])
            ->where(function ($q) use ($todayStr) {
                $q->where('blocking_tasks.status', TaskStateMachine::STATUS_OVERDUE)
                  ->orWhere(function ($q2) use ($todayStr) {
                      $q2->whereNotNull('blocking_tasks.due_date')
                         ->where('blocking_tasks.due_date', '<', $todayStr);
                  });
            })
            ->select([
                'dependent_tasks.id as task_id',
                'blocking_tasks.id as blocking_task_id',
            ])
            ->get();

        $atRiskTaskIds = $atRiskDependencies->pluck('task_id')->unique()->values()->all();
        $atRiskCount = count($atRiskTaskIds);

        // Awaiting Approval: meetings extracted but not yet reviewed
        $awaitingApprovalMeetingsQuery = Meeting::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->where('status', 'extracted');

        $awaitingApprovalCount = (clone $awaitingApprovalMeetingsQuery)->count();

        // ---------------------------------------------------------------------
        // 3. EXCEPTION LIST 1: CRITICAL BLOCKERS
        // Blocked tasks, who is waiting on whom, and how long.
        // ---------------------------------------------------------------------
        $blockedTasks = Task::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->where('status', TaskStateMachine::STATUS_BLOCKED)
            ->with([
                'owner:id,name,email',
                'activeBlocker.blockedByUser:id,name,email',
                'activeBlocker.dependsOnTask.owner:id,name,email',
            ])
            ->orderBy('updated_at', 'desc')
            ->get();

        $criticalBlockers = $blockedTasks->map(function (Task $task) use ($tz) {
            $blocker = $task->activeBlocker;
            $blockedAt = $blocker?->created_at ?: $task->updated_at;
            $diffHours = $blockedAt ? (int) Carbon::parse($blockedAt)->diffInHours(now()) : 0;
            $diffDays = $blockedAt ? (int) Carbon::parse($blockedAt)->diffInDays(now()) : 0;

            if ($diffDays > 0) {
                $duration = $diffDays === 1 ? '1 day' : "{$diffDays} days";
            } elseif ($diffHours > 0) {
                $duration = $diffHours === 1 ? '1 hour' : "{$diffHours} hours";
            } else {
                $duration = 'Less than an hour';
            }

            $dependentTask = $blocker?->dependsOnTask;
            $waitingOn = null;

            if ($dependentTask) {
                $waitingOn = [
                    'type'        => 'task',
                    'task_id'     => $dependentTask->id,
                    'task_title'  => $dependentTask->title,
                    'owner_name'  => $dependentTask->owner?->name ?? 'Unassigned',
                    'owner_email' => $dependentTask->owner?->email,
                ];
            } elseif ($blocker?->description) {
                $waitingOn = [
                    'type'    => 'description',
                    'details' => $blocker->description,
                ];
            }

            return [
                'id'               => $task->id,
                'title'            => $task->title,
                'priority'         => $task->priority,
                'due_date'         => $task->due_date ? Carbon::parse($task->due_date)->format('Y-m-d') : null,
                'owner'            => $task->owner ? [
                    'id'    => $task->owner->id,
                    'name'  => $task->owner->name,
                    'email' => $task->owner->email,
                ] : null,
                'blocked_by'       => $blocker?->blockedByUser ? [
                    'id'    => $blocker->blockedByUser->id,
                    'name'  => $blocker->blockedByUser->name,
                    'email' => $blocker->blockedByUser->email,
                ] : null,
                'reason_code'      => $blocker?->reason_code ?: 'other',
                'description'      => $blocker?->description,
                'waiting_on'       => $waitingOn,
                'blocked_since'    => $blockedAt?->toIso8601String(),
                'blocked_duration' => $duration,
            ];
        })->values();

        // ---------------------------------------------------------------------
        // 4. EXCEPTION LIST 2: AT RISK TASKS
        // Tasks whose dependency is overdue.
        // ---------------------------------------------------------------------
        $atRiskTasks = collect();
        if (!empty($atRiskTaskIds)) {
            $atRiskTasks = Task::withoutGlobalScopes()
                ->whereIn('id', $atRiskTaskIds)
                ->with([
                    'owner:id,name,email',
                    'dependencies' => function ($query) {
                        $query->where('status', 'active')->with(['dependsOnTask.owner:id,name,email']);
                    },
                ])
                ->get()
                ->map(function (Task $task) use ($today, $todayStr) {
                    $overdueDependencies = $task->dependencies
                        ->filter(function ($dep) use ($todayStr) {
                            $blockTask = $dep->dependsOnTask;
                            if (!$blockTask) return false;
                            if (in_array($blockTask->status, [TaskStateMachine::STATUS_COMPLETED, TaskStateMachine::STATUS_REJECTED], true)) {
                                return false;
                            }
                            if ($blockTask->status === TaskStateMachine::STATUS_OVERDUE) {
                                return true;
                            }
                            return $blockTask->due_date && $blockTask->due_date < $todayStr;
                        })
                        ->map(function ($dep) use ($today) {
                            $blockTask = $dep->dependsOnTask;
                            $dueDate = $blockTask->due_date ? Carbon::parse($blockTask->due_date) : null;
                            $daysOverdue = $dueDate ? $dueDate->diffInDays($today) : 0;

                            return [
                                'task_id'      => $blockTask->id,
                                'title'        => $blockTask->title,
                                'status'       => $blockTask->status,
                                'due_date'     => $dueDate?->format('Y-m-d'),
                                'days_overdue' => (int) $daysOverdue,
                                'owner'        => $blockTask->owner ? [
                                    'id'    => $blockTask->owner->id,
                                    'name'  => $blockTask->owner->name,
                                    'email' => $blockTask->owner->email,
                                ] : null,
                            ];
                        })
                        ->values();

                    return [
                        'id'                   => $task->id,
                        'title'                => $task->title,
                        'status'               => $task->status,
                        'priority'             => $task->priority,
                        'due_date'             => $task->due_date ? Carbon::parse($task->due_date)->format('Y-m-d') : null,
                        'owner'                => $task->owner ? [
                            'id'    => $task->owner->id,
                            'name'  => $task->owner->name,
                            'email' => $task->owner->email,
                        ] : null,
                        'blocking_dependencies'=> $overdueDependencies,
                    ];
                })
                ->values();
        }

        // ---------------------------------------------------------------------
        // 5. EXCEPTION LIST 3: AWAITING APPROVAL
        // Meetings extracted but not yet reviewed.
        // ---------------------------------------------------------------------
        $awaitingApprovalMeetings = $awaitingApprovalMeetingsQuery
            ->with(['creator:id,name,email', 'tasks'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Meeting $meeting) {
                $pendingCount = $meeting->tasks
                    ->whereIn('status', [TaskStateMachine::STATUS_PENDING_APPROVAL, TaskStateMachine::STATUS_DETECTED])
                    ->count();

                return [
                    'id'                  => $meeting->id,
                    'title'               => $meeting->title,
                    'meeting_date'        => $meeting->meeting_date ? Carbon::parse($meeting->meeting_date)->format('Y-m-d') : null,
                    'source'              => $meeting->source,
                    'creator'             => $meeting->creator ? [
                        'id'    => $meeting->creator->id,
                        'name'  => $meeting->creator->name,
                        'email' => $meeting->creator->email,
                    ] : null,
                    'pending_tasks_count' => $pendingCount,
                    'total_tasks_count'   => $meeting->tasks->count(),
                    'review_url'          => "/meetings/{$meeting->id}/review",
                    'created_at'          => $meeting->created_at?->toIso8601String(),
                ];
            })
            ->values();

        return response()->json([
            'counts' => [
                'active'            => $activeCount,
                'completed_today'   => $completedTodayCount,
                'overdue'           => $overdueCount,
                'blocked'           => $blockedCount,
                'at_risk'           => $atRiskCount,
                'awaiting_approval' => $awaitingApprovalCount,
            ],
            'exceptions' => [
                'critical_blockers' => $criticalBlockers,
                'at_risk'           => $atRiskTasks,
                'awaiting_approval' => $awaitingApprovalMeetings,
            ],
        ]);
    }
}
