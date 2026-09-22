<?php

namespace App\Models;

use App\Traits\BelongsToOrg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, BelongsToOrg;

    protected $table = 'users';

    protected $fillable = [
        'org_id',
        'name',
        'email',
        'password',
        'phone',
        'role',
        'manager_id',
        'status',
        'is_active',
        'password_changed_at',
        'must_change_password',
        'failed_login_count',
        'locked_until',
        'mfa_enabled_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
        'is_active' => 'boolean',
        'password_changed_at' => 'datetime',
        'must_change_password' => 'boolean',
        'failed_login_count' => 'integer',
        'locked_until' => 'datetime',
        'mfa_enabled_at' => 'datetime',
    ];

    /**
     * The user's manager.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Subordinates / direct reports managed by this user.
     */
    public function directReports(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    /**
     * Tasks assigned to this user.
     */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'owner_id');
    }

    /**
     * Tasks created by this user.
     */
    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    /**
     * Tasks approved by this user.
     */
    public function approvedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'approved_by');
    }

    /**
     * Meetings uploaded / created by this user.
     */
    public function createdMeetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'created_by');
    }

    /**
     * Notifications sent to this user.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'recipient_id');
    }

    /**
     * Comments made by this user.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'user_id');
    }

    /**
     * Audit log of login and auth events for this user.
     */
    public function loginEvents(): HasMany
    {
        return $this->hasMany(LoginEvent::class, 'user_id');
    }

    /**
     * Determine if the user account is temporarily locked.
     */
    public function isLocked(): bool
    {
        return !is_null($this->locked_until) && $this->locked_until->isFuture();
    }

    /**
     * Increment failed login attempts and lock out if threshold reached.
     */
    public function incrementFailedLogins(?\Illuminate\Http\Request $request = null): void
    {
        $this->failed_login_count = ($this->failed_login_count ?? 0) + 1;

        if ($this->failed_login_count >= 5) {
            $this->locked_until = now()->addMinutes(15);
            $this->save();

            LoginEvent::record(
                'locked_out',
                $this->email,
                $this->id,
                $this->org_id,
                $request,
                ['failed_login_count' => $this->failed_login_count, 'locked_until' => $this->locked_until->toIso8601String()]
            );
        } else {
            $this->save();

            LoginEvent::record(
                'login_failed',
                $this->email,
                $this->id,
                $this->org_id,
                $request,
                ['failed_login_count' => $this->failed_login_count]
            );
        }
    }

    /**
     * Reset the failed login counter and lockout timestamp.
     */
    public function resetFailedLogins(): void
    {
        if ($this->failed_login_count > 0 || !is_null($this->locked_until)) {
            $this->failed_login_count = 0;
            $this->locked_until = null;
            $this->save();
        }
    }

    // Role helper methods
    public function isEmployee(): bool
    {
        return $this->role === 'employee';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isExecutive(): bool
    {
        return $this->role === 'executive';
    }
}
