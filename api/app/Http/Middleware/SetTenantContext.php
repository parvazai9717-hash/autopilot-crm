<?php

namespace App\Http\Middleware;

use App\Scopes\OrgScope;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    /**
     * Handle an incoming request and initialize the tenant context.
     */
    public function handle(Request $request, Closure $next): Response
    {
        OrgScope::flush();

        $user = $request->user();

        if ($user && !empty($user->org_id)) {
            TenantContext::set((int) $user->org_id);
        }

        try {
            return $next($request);
        } finally {
            TenantContext::clear();
            OrgScope::flush();
        }
    }
}
