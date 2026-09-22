<?php

namespace App\Domain\Tasks;

use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Task metrics service.
 *
 * Single source of truth for task-counting SQL.
 * Guarantees mathematical reconciliation across all dashboard views:
 *   active = overdue + due_today + upcoming + no_deadline
 */
class TaskMetrics
{
    public const DEFINITIONS = [
        'active'            => 'Tasks with status assigned, in_progress, or blocked',
        'overdue'           => 'Active tasks with due_date earlier than today in organization timezone',
        'due_today'         => 'Active tasks with due_date equal to today in organization timezone',
        'upcoming'          => 'Active tasks with due_date later than today in organization timezone',
        'blocked'           => 'Tasks with status blocked',
        'no_deadline'       => 'Active tasks with no due_date set',
        'unassigned_active' => 'Active tasks with no assigned owner',
        'completed_today'   => 'Tasks completed since 00:00:00 today in organization timezone',
    ];

    /**
     * Compute metrics for an entire organization.
     */
    public static function forOrg(int|Organization $org, ?Carbon $asOf = null): array
    {
        $organization = $org instanceof Organization ? $org : Organization::withoutGlobalScopes()->find($org);
        $orgId = $organization?->id ?? (int) $org;
        $tz = $organization?->timezone ?: config('app.timezone', 'Asia/Karachi');

        $today = ($asOf ? $asOf->copy() : Carbon::now($tz))->setTimezone($tz)->startOfDay();
        $todayUtc = $today->copy()->setTimezone('UTC');

        $base = Task::withoutGlobalScopes()->where('tasks.org_id', $orgId);

        return self::calculate($base, $today, $todayUtc);
    }

    /**
     * Compute metrics for a single employee (tasks owned by user).
     */
    public static function forEmployee(User $user, ?Carbon $asOf = null): array
    {
        $tz = $user->organization?->timezone ?: config('app.timezone', 'Asia/Karachi');
        $today = ($asOf ? $asOf->copy() : Carbon::now($tz))->setTimezone($tz)->startOfDay();
        $todayUtc = $today->copy()->setTimezone('UTC');

        $base = Task::withoutGlobalScopes()
            ->where('tasks.org_id', $user->org_id)
            ->where('tasks.owner_id', $user->id);

        return self::calculate($base, $today, $todayUtc);
    }

    /**
     * Compute metrics for a manager (tasks owned by manager's direct reports).
     */
    public static function forManager(User $user, ?Carbon $asOf = null): array
    {
        $tz = $user->organization?->timezone ?: config('app.timezone', 'Asia/Karachi');
        $today = ($asOf ? $asOf->copy() : Carbon::now($tz))->setTimezone($tz)->startOfDay();
        $todayUtc = $today->copy()->setTimezone('UTC');

        $reportIds = User::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->where('manager_id', $user->id)
            ->pluck('id')
            ->all();

        $base = Task::withoutGlobalScopes()
            ->where('tasks.org_id', $user->org_id)
            ->whereIn('tasks.owner_id', $reportIds);

        return self::calculate($base, $today, $todayUtc);
    }

    /**
     * Internal calculator applying predicates.
     */
    private static function calculate(Builder $base, Carbon $today, Carbon $todayUtc): array
    {
        $active           = TaskPredicates::open(clone $base)->count();
        $overdue          = TaskPredicates::overdue(clone $base, $today)->count();
        $dueToday         = TaskPredicates::dueToday(clone $base, $today)->count();
        $upcoming         = TaskPredicates::upcoming(clone $base, $today)->count();
        $blocked          = TaskPredicates::blocked(clone $base)->count();
        $noDeadline       = TaskPredicates::noDeadline(clone $base)->count();
        $unassignedActive = TaskPredicates::unassignedActive(clone $base)->count();
        $completedToday   = TaskPredicates::completedToday(clone $base, $todayUtc)->count();

        return [
            'active'            => $active,
            'overdue'           => $overdue,
            'due_today'         => $dueToday,
            'upcoming'          => $upcoming,
            'blocked'           => $blocked,
            'no_deadline'       => $noDeadline,
            'unassigned_active' => $unassignedActive,
            'completed_today'   => $completedToday,
            'as_of'             => $today->toIso8601String(),
            'definitions'       => self::DEFINITIONS,
        ];
    }
}
