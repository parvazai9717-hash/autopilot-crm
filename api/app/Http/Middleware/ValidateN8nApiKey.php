<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateN8nApiKey
{
    /**
     * Handle an incoming request from n8n automation layer.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $headerKey = $request->header('X-API-Key');

        if (empty($headerKey)) {
            return ApiResponse::error(
                'MISSING_API_KEY',
                'X-API-Key header is missing.',
                401
            );
        }

        $configuredKey = config('autopilot.n8n_api_key');
        $org = \App\Models\Organization::withoutGlobalScopes()->find(1);
        $orgKey = $org?->settings['api_key'] ?? null;

        $isValid = false;
        if (!empty($configuredKey) && hash_equals((string) $configuredKey, (string) $headerKey)) {
            $isValid = true;
        } elseif (!empty($orgKey) && hash_equals((string) $orgKey, (string) $headerKey)) {
            $isValid = true;
        }

        if (!$isValid) {
            return ApiResponse::error(
                'INVALID_API_KEY',
                'Invalid API key provided.',
                401
            );
        }

        return $next($request);
    }
}
