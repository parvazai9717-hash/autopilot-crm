<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Scopes\OrgScope;
use App\Services\TenantContext;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateN8nApiKey
{
    /**
     * Handle an incoming request from n8n automation layer.
     * Validates API key without hardcoding any tenant ID.
     */
    public function handle(Request $request, Closure $next): Response
    {
        OrgScope::flush();

        $headerKey = $request->header('X-API-Key');

        if (empty($headerKey)) {
            return ApiResponse::error(
                'MISSING_API_KEY',
                'X-API-Key header is missing.',
                401
            );
        }

        $configuredKey = config('autopilot.n8n_api_key');
        $rawTenantId = $request->header('X-Tenant-ID') 
            ?? $request->header('X-Org-ID') 
            ?? $request->input('org_id');

        $isValid = false;
        $matchedOrgId = null;

        // 1. Verify against global platform n8n key
        if (!empty($configuredKey) && hash_equals((string) $configuredKey, (string) $headerKey)) {
            $isValid = true;
            if (!empty($rawTenantId)) {
                $matchedOrgId = (int) $rawTenantId;
            }
        }

        // 2. If not matched against global key, check tenant-specific API key
        if (!$isValid) {
            if (!empty($rawTenantId)) {
                $org = Organization::withoutGlobalScopes()->find((int) $rawTenantId);
                $orgKey = data_get($org?->settings, 'api_key');

                if (!empty($orgKey) && hash_equals((string) $orgKey, (string) $headerKey)) {
                    $isValid = true;
                    $matchedOrgId = $org->id;
                }
            } else {
                // If tenant header is not passed, look up matching organization by api_key in settings
                $orgs = Organization::withoutGlobalScopes()->get();
                foreach ($orgs as $org) {
                    $orgKey = data_get($org->settings, 'api_key');
                    if (!empty($orgKey) && hash_equals((string) $orgKey, (string) $headerKey)) {
                        $isValid = true;
                        $matchedOrgId = $org->id;
                        break;
                    }
                }
            }
        }

        if (!$isValid) {
            return ApiResponse::error(
                'INVALID_API_KEY',
                'Invalid API key provided.',
                401
            );
        }

        // If a tenant was matched, establish TenantContext
        if ($matchedOrgId !== null) {
            TenantContext::set($matchedOrgId);
        }

        try {
            return $next($request);
        } finally {
            TenantContext::clear();
            OrgScope::flush();
        }
    }
}
