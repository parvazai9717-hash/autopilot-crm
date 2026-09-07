<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Catch any PHP fatal / exception that kills the process before Laravel boots.
// Writes directly to storage/logs/laravel.log so the log is never empty.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $msg = date('Y-m-d H:i:s') . ' [FATAL BEFORE LARAVEL] '
             . $err['message'] . ' in ' . $err['file'] . ':' . $err['line'] . PHP_EOL;
        file_put_contents(__DIR__ . '/../storage/logs/laravel.log', $msg, FILE_APPEND | LOCK_EX);
    }
});

set_exception_handler(function (Throwable $e) {
    $msg = date('Y-m-d H:i:s') . ' [UNCAUGHT BEFORE LARAVEL] '
         . get_class($e) . ': ' . $e->getMessage()
         . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL
         . $e->getTraceAsString() . PHP_EOL;
    file_put_contents(__DIR__ . '/../storage/logs/laravel.log', $msg, FILE_APPEND | LOCK_EX);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['code' => 'INTERNAL_SERVER_ERROR', 'message' => $e->getMessage(), 'field' => null]]);
    exit(1);
});

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
