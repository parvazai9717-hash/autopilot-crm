<?php

use Laravel\Sanctum\Sanctum;

return [

    'stateful' => array_values(array_unique(array_filter(array_merge(
        [
            'localhost',
            'localhost:3000',
            'localhost:5173',
            '127.0.0.1',
            '127.0.0.1:8000',
            '127.0.0.1:5173',
            '::1',
            '*.easypanel.host',
            Sanctum::currentApplicationUrlWithPort(),
            env('APP_URL') ? parse_url(env('APP_URL'), PHP_URL_HOST) : null,
            env('FRONTEND_URL') ? parse_url(env('FRONTEND_URL'), PHP_URL_HOST) : null,
        ],
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', ''))
    )))),

    'guard' => ['web'],

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
