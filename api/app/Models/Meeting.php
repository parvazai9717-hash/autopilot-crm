<?php

namespace App\Models;

use App\Traits\BelongsToOrg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    use HasFactory, BelongsToOrg;

    protected $table = 'meetings';

    protected $fillable = [
        'org_id',
        'title',
        'meeting_date',
        'timezone',
        'source',
        'created_by',
        'audio_path',
        'audio_duration_seconds',
        'transcript',
        'summary',
        'status',
        'error_message',
        'processing_started_at',
    ];

    protected $casts = [
        'meeting_date' => 'date:Y-m-d',
        'processing_started_at' => 'datetime',
        'audio_duration_seconds' => 'integer',
    ];

    /**
     * User who created / uploaded this meeting.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Participants in this meeting.
     */
    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class, 'meeting_id');
    }

    /**
     * Tasks extracted from this meeting.
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'meeting_id');
    }
}
