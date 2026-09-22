<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'login_events';

    protected $fillable = [
        'user_id',
        'org_id',
        'email',
        'event_type',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * Record a login event audit entry.
     */
    public static function record(
        string $eventType,
        string $email,
        ?int $userId = null,
        ?int $orgId = null,
        ?Request $request = null,
        array $metadata = []
    ): self {
        return static::create([
            'user_id' => $userId,
            'org_id' => $orgId,
            'email' => $email,
            'event_type' => $eventType,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 500) : null,
            'metadata' => !empty($metadata) ? $metadata : null,
            'created_at' => now(),
        ]);
    }
}
