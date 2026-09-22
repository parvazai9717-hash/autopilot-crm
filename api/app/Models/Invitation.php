<?php

namespace App\Models;

use App\Traits\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Invitation extends Model
{
    use BelongsToOrg;

    protected $table = 'invitations';

    protected $fillable = [
        'org_id',
        'email',
        'role',
        'manager_id',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Check if invitation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if invitation was already accepted.
     */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Generate an invitation token and hashed token.
     *
     * @return array{plain: string, hash: string}
     */
    public static function generateToken(): array
    {
        $plain = Str::random(64);
        $hash = hash('sha256', $plain);

        return [
            'plain' => $plain,
            'hash' => $hash,
        ];
    }
}
