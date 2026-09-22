<?php

namespace App\Services;

use App\Models\Task;

/**
 * ActionItemEligibility service.
 *
 * Evaluates whether an extracted task is valid and eligible for approval.
 * Canonical gate for single-item approve and bulk approve.
 */
class ActionItemEligibility
{
    public const OWNER_REQUIRED              = 'OWNER_REQUIRED';
    public const OWNER_AMBIGUOUS             = 'OWNER_AMBIGUOUS';
    public const OWNER_NOT_IN_ORG            = 'OWNER_NOT_IN_ORG';
    public const OWNER_INACTIVE              = 'OWNER_INACTIVE';
    public const TITLE_REQUIRED              = 'TITLE_REQUIRED';
    public const DEADLINE_REQUIRED           = 'DEADLINE_REQUIRED';
    public const DEADLINE_INVALID            = 'DEADLINE_INVALID';
    public const CONDITIONAL_UNACKNOWLEDGED  = 'CONDITIONAL_UNACKNOWLEDGED';
    public const NOT_PENDING                 = 'NOT_PENDING';
    public const MEETING_NOT_REVIEWABLE      = 'MEETING_NOT_REVIEWABLE';

    /**
     * Evaluate if a task can be approved.
     *
     * @param Task $task
     * @return array{eligible: bool, blockers: array<string>}
     */
    public static function evaluate(Task $task): array
    {
        $blockers = [];

        // 1. Must be in a pending state
        if (!in_array($task->status, ['pending_approval', 'detected'], true)) {
            $blockers[] = self::NOT_PENDING;
        }

        // 2. Title required
        if (empty(trim($task->title ?? ''))) {
            $blockers[] = self::TITLE_REQUIRED;
        }

        // 3. Owner required
        if (empty($task->owner_id)) {
            $blockers[] = self::OWNER_REQUIRED;
        }

        // 4. Ambiguity check
        if ($task->owner_ambiguous || $task->owner_state === 'ambiguous') {
            $blockers[] = self::OWNER_AMBIGUOUS;
        }

        // 5. Unmatched check
        if ($task->owner_state === 'unmatched' && empty($task->owner_id)) {
            if (!in_array(self::OWNER_REQUIRED, $blockers, true)) {
                $blockers[] = self::OWNER_REQUIRED;
            }
        }

        // 6. Owner tenant and activity check
        if ($task->owner_id) {
            $owner = $task->relationLoaded('owner') ? $task->owner : $task->owner()->first();
            if (!$owner || $owner->org_id !== $task->org_id) {
                $blockers[] = self::OWNER_NOT_IN_ORG;
            } elseif ((isset($owner->is_active) && !$owner->is_active) || (isset($owner->status) && $owner->status !== 'active')) {
                $blockers[] = self::OWNER_INACTIVE;
            }
        }

        // 7. Conditional task acknowledgment
        if ($task->conditional && !$task->conditional_ack) {
            $blockers[] = self::CONDITIONAL_UNACKNOWLEDGED;
        }

        // 8. Meeting check
        if ($task->meeting_id) {
            $meeting = $task->relationLoaded('meeting') ? $task->meeting : $task->meeting()->first();
            if ($meeting && in_array($meeting->status, ['failed'], true)) {
                $blockers[] = self::MEETING_NOT_REVIEWABLE;
            }
        }

        return [
            'eligible' => empty($blockers),
            'blockers' => array_values(array_unique($blockers)),
        ];
    }
}
