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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
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
