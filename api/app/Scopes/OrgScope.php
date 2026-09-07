<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrgScope implements Scope
{
    // Guards the recursion: resolving the current user loads the
    // User model, which itself carries this scope.
    protected static bool $resolving = false;

    // Cached for the lifetime of the request.
    protected static ?int $orgId = null;
    protected static bool $resolved = false;

    public function apply(Builder $builder, Model $model): void
    {
        $orgId = static::currentOrgId();

        if ($orgId !== null) {
            $builder->where($model->getTable() . '.org_id', $orgId);
        }
    }

    public static function currentOrgId(): ?int
    {
        if (static::$resolved) {
            return static::$orgId;
        }

        // Already inside a resolution — return without constraining.
        // This is what breaks the loop.
        if (static::$resolving) {
            return null;
        }

        static::$resolving = true;

        try {
            $userId = static::authenticatedUserId();

            if ($userId === null) {
                return null;
            }

            // Raw query on purpose: bypasses Eloquent so this scope
            // is not applied again while we are resolving it.
            $orgId = DB::table('users')->where('id', $userId)->value('org_id');

            static::$orgId = $orgId !== null ? (int) $orgId : null;
            static::$resolved = true;

            return static::$orgId;
        } finally {
            static::$resolving = false;
        }
    }

    // Read the id straight from the session. Auth::user() AND
    // Auth::id() both hydrate the User model, which is the problem.
    protected static function authenticatedUserId(): ?int
    {
        $guard = Auth::guard();

        if (method_exists($guard, 'getName')) {
            $id = session()->get($guard->getName());

            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }

    // Must be called whenever the authenticated user changes.
    public static function flush(): void
    {
        static::$orgId = null;
        static::$resolved = false;
        static::$resolving = false;
    }
}
