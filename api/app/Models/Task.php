<?php

namespace App\Models;

use App\Traits\BelongsToOrg;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    use HasFactory, BelongsToOrg;

    protected $table = 'tasks';

    protected $fillable = [
        'org_id',
        'meeting_id',
        'title',
        'description',
        'owner_id',
        'owner_name_raw',
        'owner_ambiguous',
        'created_by',
        'approved_by',
        'priority',
        'status',
        'previous_status',
        'due_date',
        'deadline_phrase',
        'source_text',
        'conditional',
        'owner_confidence',
        'deadline_confidence',
        'action_confidence',
        'last_reminder_at',
        'escalation_level',
        'approved_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'owner_ambiguous' => 'boolean',
        'conditional' => 'boolean',
        'owner_confidence' => 'float',
        'deadline_confidence' => 'float',
        'action_confidence' => 'float',
        'escalation_level' => 'integer',
        'due_date' => 'date:Y-m-d',
        'last_reminder_at' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Meeting from which the task was extracted.
     */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    /**
     * Assigned owner of the task.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * User who created the task.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who approved the task.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Audit trail of events for this task.
     */
    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class, 'task_id')->orderBy('created_at', 'asc');
    }

    /**
     * Blockers recorded on this task.
     */
    public function blockers(): HasMany
    {
        return $this->hasMany(TaskBlocker::class, 'task_id');
    }

    /**
     * The current active (unresolved) blocker for this task.
     */
    public function activeBlocker(): HasOne
    {
        return $this->hasOne(TaskBlocker::class, 'task_id')
            ->whereNull('resolved_at')
            ->latest('created_at');
    }

    /**
     * Dependencies declared on other tasks (this task depends on others).
     */
    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'task_id');
    }

    /**
     * Tasks that depend on this task.
     */
    public function dependentTasks(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'depends_on_task_id');
    }

    /**
     * Comments and attachments for this task.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }

    /**
     * Notifications triggered for this task.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'task_id');
    }

    /**
     * Check if task is overdue based on org timezone.
     */
    public function isOverdue(?string $orgTimezone = null): bool
    {
        if (!$this->due_date || in_array($this->status, ['completed', 'rejected'])) {
            return false;
        }

        $tz = $orgTimezone ?? ($this->organization?->timezone ?? config('app.timezone', 'Asia/Karachi'));
        $today = Carbon::now($tz)->startOfDay();
        $dueDate = Carbon::parse($this->due_date, $tz)->startOfDay();

        return $dueDate->lt($today);
    }
}
