<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return ['status' => 'ok', 'app' => config('app.name')];
});

Route::prefix('internal/v1')->middleware(['n8n.key'])->group(function () {
    Route::post('/runs/claim', [App\Http\Controllers\Internal\V1\RunController::class, 'claim']);
    Route::get('/orgs/active', [App\Http\Controllers\Internal\V1\OrgsController::class, 'active']);
});
