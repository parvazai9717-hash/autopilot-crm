<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'logout', 'v1/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter([
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        env('FRONTEND_URL'),
        env('APP_URL'),
    ]))),

    'allowed_origins_patterns' => [
        '#^http://localhost:\d+$#',
        '#^http://127\.0\.0\.1:\d+$#',
        '#^https?://.*\.easypanel\.host(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Signature', 'Idempotency-Key'],

    'max_age' => 0,

    'supports_credentials' => true,

];
