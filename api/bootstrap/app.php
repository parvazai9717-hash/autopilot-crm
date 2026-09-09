<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\ClearStaleRememberCookie;
use App\Http\Middleware\HandleIdempotency;
use App\Http\Middleware\ValidateN8nApiKey;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->statefulApi();

        $middleware->validateCsrfTokens(except: [
            'api/auth/login',
            'api/auth/logout',
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => null);
        $middleware->api(prepend: [
            // Must run first so stale remember_web_* cookies are caught
            // before any auth resolution happens, returning 401 not 500.
            ClearStaleRememberCookie::class,
        ]);
        $middleware->api(append: [
            HandleIdempotency::class,
        ]);
        $middleware->alias([
            'n8n.key' => ValidateN8nApiKey::class,
            'idempotency' => HandleIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ApiException $e, Request $request) {
            return $e->render($request);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $errors = $e->errors();
                $firstField = array_key_first($errors);
                $firstMessage = !empty($errors[$firstField]) ? $errors[$firstField][0] : $e->getMessage();

                return ApiResponse::error(
                    'VALIDATION_ERROR',
                    $firstMessage,
                    422,
                    $firstField
                );
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ApiResponse::error(
                    'UNAUTHENTICATED',
                    'Unauthenticated.',
                    401
                );
            }
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ApiResponse::error(
                    'FORBIDDEN',
                    'This action is unauthorized.',
                    403
                );
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ApiResponse::error(
                    'NOT_FOUND',
                    'Resource not found.',
                    404
                );
            }
        });

        $exceptions->render(function (ThrottleRequestsException|TooManyRequestsHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ApiResponse::error(
                    'RATE_LIMIT_EXCEEDED',
                    'Too many requests. Please try again later.',
                    429
                );
            }
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return ApiResponse::error(
                    'METHOD_NOT_ALLOWED',
                    'The requested method is not supported for this route.',
                    405
                );
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    400 => 'BAD_REQUEST',
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    409 => 'ILLEGAL_TRANSITION',
                    422 => 'VALIDATION_ERROR',
                    429 => 'RATE_LIMIT_EXCEEDED',
                    default => 'HTTP_ERROR',
                };

                return ApiResponse::error(
                    $code,
                    $e->getMessage() ?: 'An HTTP error occurred.',
                    $status
                );
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $message = config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.';

                return ApiResponse::error(
                    'INTERNAL_SERVER_ERROR',
                    $message,
                    500
                );
            }
        });
    })->create();
