<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catches two classes of stale-session failure that otherwise produce a 500
 * on API routes:
 *
 * 1. Stale remember_web_* cookie — the user row no longer exists (e.g. after
 *    migrate:fresh).  Laravel's retrieveByToken() can throw instead of
 *    returning null in some edge cases.
 *
 * 2. Password-hash mismatch — the session stores a SHA-1 of the user's
 *    bcrypt hash at login time (password_hash_web key).  If the DB was
 *    re-seeded the hash changes and AuthenticateSession invalidates the
 *    session then issues a redirect.  On API routes that redirect becomes
 *    an unrendered Response object that crashes with a 500.
 *
 * In both cases we clear the offending cookies and return a clean JSON 401
 * so the browser drops back to the login page gracefully.
 */
class ClearStaleRememberCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);

            // AuthenticateSession does NOT throw — it calls logout() and then
            // returns a RedirectResponse.  On an API/JSON route that redirect
            // is meaningless; detect it and convert to 401.
            if (
                $response instanceof \Illuminate\Http\RedirectResponse
                && ($request->expectsJson() || $request->is('api/*'))
            ) {
                \Illuminate\Support\Facades\Log::warning('AuthenticateSession issued redirect on API route (password hash mismatch); converting to 401.', [
                    'redirect_to' => $response->getTargetUrl(),
                    'ip'          => $request->ip(),
                ]);

                return $this->staleSessionResponse($request);
            }

            return $response;

        } catch (\Throwable $e) {
            if ($this->isStaleSessionFailure($e)) {
                \Illuminate\Support\Facades\Log::warning('Stale session/remember cookie detected; returning 401.', [
                    'exception' => get_class($e),
                    'error'     => $e->getMessage(),
                    'ip'        => $request->ip(),
                ]);

                return $this->staleSessionResponse($request);
            }

            throw $e;
        }
    }

    /**
     * Build a JSON 401 response and expire all remember_web_* cookies
     * so the browser has a clean slate for the next login attempt.
     */
    private function staleSessionResponse(Request $request): Response
    {
        $response = response()->json([
            'error' => [
                'code'    => 'UNAUTHENTICATED',
                'message' => 'Your session has expired. Please log in again.',
                'field'   => null,
            ],
        ], 401);

        foreach ($request->cookies->keys() as $name) {
            if (
                str_starts_with($name, 'remember_web_')
                || $name === config('session.cookie', 'laravel_session')
            ) {
                $response->headers->setCookie(
                    cookie($name, '', -2628000, '/', null, false, true)
                );
            }
        }

        return $response;
    }

    /**
     * Recognise exception types that indicate a stale session or remember token.
     */
    private function isStaleSessionFailure(\Throwable $e): bool
    {
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return true;
        }

        if ($e instanceof TokenMismatchException) {
            return true;
        }

        if ($e instanceof AuthenticationException) {
            return true;
        }

        $msg = $e->getMessage();

        return str_contains($msg, 'remember_web')
            || str_contains($msg, 'remember_token')
            || str_contains($msg, 'retrieveByToken')
            || str_contains($msg, 'password_hash_web')
            || (str_contains($msg, 'No query results for model') && str_contains($msg, 'User'));
    }
}
