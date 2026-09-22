<?php

namespace App\Domain\Tasks;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for task query filtering and state predicates.
 *
 * Per SPEC.md Section 8 and Brief Phase 1:
 * Every controller, dashboard, and automation query MUST use these predicates
 * to ensure mathematical reconciliation across all views.
 */
class TaskPredicates
{
    /**
     * Active/open tasks (work remaining to be done).
     * Excludes pending approval, detected, completed, and rejected.
     */
    public static function open(Builder $query): Builder
    {
        return $query->whereIn('tasks.status', [
            TaskStatus::Assigned->value,
            TaskStatus::InProgress->value,
            TaskStatus::Blocked->value,
            // Backwards compatibility with legacy stored statuses prior to data migration:
            'overdue',
            'escalated',
        ]);
    }

    /**
     * Overdue tasks: Open tasks whose due_date is strictly before today in the org timezone.
     */
    public static function overdue(Builder $query, Carbon|string $today): Builder
    {
        $todayStr = $today instanceof Carbon ? $today->format('Y-m-d') : $today;

        return $query->where(function (Builder $sub) use ($todayStr) {
            // Legacy stored 'overdue' rows
            $sub->where('tasks.status', 'overdue')
                ->orWhere(function (Builder $inner) use ($todayStr) {
                    self::open($inner);
                    $inner->whereNotNull('tasks.due_date')
                          ->where('tasks.due_date', '<', $todayStr);
                });
        });
    }

    /**
     * Due Today tasks: Open tasks whose due_date is exactly today in the org timezone.
     */
    public static function dueToday(Builder $query, Carbon|string $today): Builder
    {
        $todayStr = $today instanceof Carbon ? $today->format('Y-m-d') : $today;

        return self::open($query)
            ->whereNotNull('tasks.due_date')
            ->where('tasks.due_date', '=', $todayStr);
    }

    /**
     * Upcoming tasks: Open tasks whose due_date is strictly after today in the org timezone.
     */
    public static function upcoming(Builder $query, Carbon|string $today): Builder
    {
        $todayStr = $today instanceof Carbon ? $today->format('Y-m-d') : $today;

        return self::open($query)
            ->whereNotNull('tasks.due_date')
            ->where('tasks.due_date', '>', $todayStr);
    }

    /**
     * No Deadline tasks: Open tasks with no due date specified.
     */
    public static function noDeadline(Builder $query): Builder
    {
        return self::open($query)
            ->whereNull('tasks.due_date');
    }

    /**
     * Blocked tasks: status = blocked.
     */
    public static function blocked(Builder $query): Builder
    {
        return $query->where('tasks.status', TaskStatus::Blocked->value);
    }

    /**
     * Completed tasks: status = completed.
     */
    public static function completed(Builder $query): Builder
    {
        return $query->where('tasks.status', TaskStatus::Completed->value);
    }

    /**
     * Completed today: status = completed and completed_at >= todayUtc.
     */
    public static function completedToday(Builder $query, Carbon $todayUtc): Builder
    {
        return $query->where('tasks.status', TaskStatus::Completed->value)
            ->where('tasks.completed_at', '>=', $todayUtc);
    }

    /**
     * Unassigned active tasks: Open tasks that have no assigned owner.
     */
    public static function unassignedActive(Builder $query): Builder
    {
        return self::open($query)
            ->whereNull('tasks.owner_id');
    }
}
