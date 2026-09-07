<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleIdempotency
{
    /**
     * Handle an incoming request with optional idempotency key.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('POST')) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');
        if (empty($key)) {
            return $next($request);
        }

        $twentyFourHoursAgo = now()->subHours(24);

        $existing = IdempotencyKey::where('key', $key)
            ->where('created_at', '>=', $twentyFourHoursAgo)
            ->first();

        if ($existing) {
            return response()->json(
                $existing->response_body,
                200,
                ['X-Idempotent-Replayed' => 'true']
            );
        }

        $response = $next($request);

        // Store successful responses (2xx)
        if ($response->isSuccessful()) {
            $content = $response->getContent();
            $body = json_decode($content, true);

            if (is_array($body)) {
                // Remove expired duplicate if existed
                IdempotencyKey::where('key', $key)->delete();

                IdempotencyKey::create([
                    'key' => $key,
                    'endpoint' => $request->path(),
                    'response_body' => $body,
                    'created_at' => now(),
                ]);
            }
        }

        return $response;
    }
}
