<?php

use App\Http\Controllers\Api\V1\ActionItemController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TaskEventController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication Routes (Sanctum SPA)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    // Browser-facing Meeting Ingestion & Status (Admin Only)
    Route::post('/meetings', [App\Http\Controllers\Api\MeetingController::class, 'store']);
    Route::post('/meetings/upload', [App\Http\Controllers\Api\MeetingController::class, 'upload']);
    Route::get('/meetings/{id}/status', [App\Http\Controllers\Api\MeetingController::class, 'status']);
    Route::post('/meetings/{id}/retry', [App\Http\Controllers\Api\MeetingController::class, 'retry']);

    // Meeting Review & Management
    Route::get('/meetings/{id}', [App\Http\Controllers\Api\ReviewController::class, 'show']);
    Route::get('/meetings/{id}/review', [App\Http\Controllers\Api\ReviewController::class, 'show']);
    Route::post('/meetings/{id}/approve-all', [App\Http\Controllers\Api\ReviewController::class, 'approveAll']);

    // Task Actions (Review Screen)
    Route::patch('/tasks/{id}', [App\Http\Controllers\Api\ReviewController::class, 'updateTask']);
    Route::post('/tasks/{id}/approve', [App\Http\Controllers\Api\ReviewController::class, 'approveTask']);
    Route::post('/tasks/{id}/reject', [App\Http\Controllers\Api\ReviewController::class, 'rejectTask']);

    // Org Users Roster
    Route::get('/users', [UserController::class, 'index']);

    // ---------------------------------------------------------------
    // Employee "My Tasks" screen (Phase 9)
    // ---------------------------------------------------------------
    Route::prefix('my-tasks')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\EmployeeTaskController::class, 'index']);
        Route::post('/{id}/start',   [App\Http\Controllers\Api\EmployeeTaskController::class, 'start']);
        Route::post('/{id}/complete',[App\Http\Controllers\Api\EmployeeTaskController::class, 'complete']);
        Route::post('/{id}/block',   [App\Http\Controllers\Api\EmployeeTaskController::class, 'block']);
        Route::post('/{id}/unblock', [App\Http\Controllers\Api\EmployeeTaskController::class, 'unblock']);
        Route::post('/{id}/comment', [App\Http\Controllers\Api\EmployeeTaskController::class, 'comment']);
        Route::post('/{id}/attach',  [App\Http\Controllers\Api\EmployeeTaskController::class, 'attach']);
    });

    // ---------------------------------------------------------------
    // Manager Dashboard (Phase 10)
    // ---------------------------------------------------------------
    Route::prefix('manager')->group(function () {
        Route::get('/dashboard',              [App\Http\Controllers\Api\ManagerDashboardController::class, 'index']);
        Route::post('/tasks/{id}/reassign',   [App\Http\Controllers\Api\ManagerDashboardController::class, 'reassign']);
        Route::patch('/tasks/{id}/deadline',  [App\Http\Controllers\Api\ManagerDashboardController::class, 'updateDeadline']);
        Route::post('/tasks/{id}/comment',    [App\Http\Controllers\Api\ManagerDashboardController::class, 'comment']);
        Route::post('/tasks/{id}/resolve-blocker', [App\Http\Controllers\Api\ManagerDashboardController::class, 'resolveBlocker']);
        Route::post('/tasks/{id}/escalate',   [App\Http\Controllers\Api\ManagerDashboardController::class, 'escalate']);
    });

    // ---------------------------------------------------------------
    // Executive Dashboard (Phase 11)
    // ---------------------------------------------------------------
    Route::prefix('executive')->group(function () {
        Route::get('/dashboard', [App\Http\Controllers\Api\ExecutiveDashboardController::class, 'index']);
    });

    // ---------------------------------------------------------------
    // Admin Center (Phase 12)
    // ---------------------------------------------------------------
    Route::prefix('admin')->group(function () {
        Route::get('/users',                [App\Http\Controllers\Api\AdminController::class, 'users']);
        Route::post('/users',               [App\Http\Controllers\Api\AdminController::class, 'storeUser']);
        Route::put('/users/{id}',           [App\Http\Controllers\Api\AdminController::class, 'updateUser']);

        Route::get('/settings',             [App\Http\Controllers\Api\AdminController::class, 'settings']);
        Route::put('/settings',             [App\Http\Controllers\Api\AdminController::class, 'updateSettings']);
        Route::post('/api-key/regenerate',  [App\Http\Controllers\Api\AdminController::class, 'regenerateApiKey']);

        Route::get('/webhooks',             [App\Http\Controllers\Api\AdminController::class, 'webhooks']);
        Route::post('/webhooks/{id}/resend', [App\Http\Controllers\Api\AdminController::class, 'resendWebhook']);
    });
});

Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Public Signed Media Routes (Expiring Signed URLs for Whisper / n8n)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {
    Route::get('/meetings/{id}/audio', [MeetingController::class, 'downloadAudio'])
        ->middleware('signed')
        ->name('meetings.audio.download');
});

/*
|--------------------------------------------------------------------------
| Machine API Routes (Protected by X-API-Key, Rate Limiting, Idempotency)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware(['n8n.key', 'throttle:n8n-api'])->group(function () {
    // Health check ping
    Route::get('/ping', function () {
        return response()->json(['ok' => true, 'timestamp' => now()->toIso8601String()]);
    });

    // Users roster
    Route::get('/users', [UserController::class, 'index']);

    // Org policy / settings
    Route::get('/orgs/{id}/settings', [OrganizationController::class, 'settings']);
    Route::get('/organizations/{id}/settings', [OrganizationController::class, 'settings']);

    // Meetings
    Route::post('/meetings', [MeetingController::class, 'store']);
    Route::post('/meetings/upload', [MeetingController::class, 'upload']);
    Route::patch('/meetings/{id}', [MeetingController::class, 'update']);
    Route::post('/meetings/{id}/retry', [MeetingController::class, 'retry']);

    // Action items ingestion
    Route::post('/action-items', [ActionItemController::class, 'store']);

    // Tasks list
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::post('/tasks/{id}/escalation', [TaskController::class, 'escalation']);

    // Task events
    Route::post('/task-events', [TaskEventController::class, 'store']);
});
