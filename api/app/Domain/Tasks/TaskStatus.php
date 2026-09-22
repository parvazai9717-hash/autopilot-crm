<?php

namespace App\Domain\Tasks;

/**
 * Canonical task status enum.
 *
 * Per SPEC.md Section 8 and Brief Phase 1:
 * Stored statuses are ONLY lifecycle states:
 *   pending_approval, assigned, in_progress, blocked, completed, rejected.
 *
 * "overdue" and "open" are VIRTUAL PREDICATES computed on read, NEVER stored.
 */
enum TaskStatus: string
{
    case Detected        = 'detected';          // Legacy/pre-approval (transitional)
    case PendingApproval = 'pending_approval';
    case Approved        = 'approved';          // Pre-assignment (transitional)
    case Assigned        = 'assigned';
    case InProgress      = 'in_progress';
    case Blocked         = 'blocked';
    case Completed       = 'completed';
    case Rejected        = 'rejected';

    /**
     * Virtual filter aliases recognized by API queries, but NEVER stored.
     */
    public const VIRTUAL_FILTERS = ['overdue', 'open'];

    /**
     * Statuses considered "open" / "active" (work remaining).
     */
    public const OPEN_STATUSES = [
        self::Assigned->value,
        self::InProgress->value,
        self::Blocked->value,
    ];

    /**
     * Terminal statuses (work finished or discarded).
     */
    public const TERMINAL_STATUSES = [
        self::Completed->value,
        self::Rejected->value,
    ];

    /**
     * Pre-work statuses (not yet active commitments).
     */
    public const PENDING_STATUSES = [
        self::Detected->value,
        self::PendingApproval->value,
    ];

    public function isOpen(): bool
    {
        return in_array($this->value, self::OPEN_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->value, self::TERMINAL_STATUSES, true);
    }

    public static function openValues(): array
    {
        return self::OPEN_STATUSES;
    }

    public static function terminalValues(): array
    {
        return self::TERMINAL_STATUSES;
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
