<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $table = 'organizations';

    protected $fillable = [
        'name',
        'timezone',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    /**
     * Users belonging to this organization.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'org_id');
    }

    /**
     * Meetings belonging to this organization.
     */
    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'org_id');
    }

    /**
     * Tasks belonging to this organization.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'org_id');
    }
}
