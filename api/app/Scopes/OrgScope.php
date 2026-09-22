<?php

namespace App\Scopes;

use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        if (TenantContext::isBypassed()) {
            return;
        }

        $orgId = static::currentOrgId();

        if ($orgId !== null) {
            $builder->where($model->getTable() . '.org_id', $orgId);
            return;
        }

        // Break recursion when resolving user during auth
        if (static::$resolving) {
            return;
        }

        // Special case: During authentication retrieval (e.g. Auth::attempt on User model)
        // when no user is logged in yet, allow finding the user by email/credentials.
        if ($model instanceof User && !Auth::check() && !TenantContext::has()) {
            return;
        }

        // In artisan CLI commands (e.g. migrations) outside unit tests when no auth context is set
        if (app()->runningInConsole() && !app()->runningUnitTests() && !Auth::check() && !TenantContext::has()) {
            return;
        }

        // Fail closed: no tenant context or authenticated user present
        Log::warning("OrgScope failed closed on table {$model->getTable()}: No tenant context found.");
        $builder->whereRaw('1 = 0');
    }

    public static function currentOrgId(): ?int
    {
        if (TenantContext::has()) {
            return TenantContext::get();
        }

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
            // First check if Auth user is already resolved (e.g. Sanctum, actingAs)
            if (Auth::check()) {
                $user = Auth::user();
                if ($user && isset($user->org_id)) {
                    static::$orgId = (int) $user->org_id;
                    static::$resolved = true;
                    return static::$orgId;
                }
            }

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

    // Must be called whenever the authenticated user changes or request finishes.
    public static function flush(): void
    {
        static::$orgId = null;
        static::$resolved = false;
        static::$resolving = false;
    }
}
