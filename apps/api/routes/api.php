<?php

use App\Http\Controllers\AiSessionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BuildController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\GitController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\QueueController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/admin/settings', [SettingsController::class, 'show']);
    Route::put('/admin/settings', [SettingsController::class, 'update']);

    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces', [WorkspaceController::class, 'store']);
    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show']);
    Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'destroy']);
    Route::post('/workspaces/{workspace}/clone', [WorkspaceController::class, 'clone']);

    Route::get('/workspaces/{workspace}/files', [FileController::class, 'tree']);
    Route::get('/workspaces/{workspace}/file', [FileController::class, 'show']);
    Route::put('/workspaces/{workspace}/file', [FileController::class, 'update']);
    Route::post('/workspaces/{workspace}/file', [FileController::class, 'store']);

    Route::get('/workspaces/{workspace}/git/status', [GitController::class, 'status']);
    Route::get('/workspaces/{workspace}/git/diff', [GitController::class, 'diff']);
    Route::get('/workspaces/{workspace}/git/log', [GitController::class, 'log']);
    Route::post('/workspaces/{workspace}/git/commit', [GitController::class, 'commit']);
    Route::post('/workspaces/{workspace}/git/restore', [GitController::class, 'restore']);
    Route::put('/workspaces/{workspace}/git/remote', [GitController::class, 'remote']);
    Route::post('/workspaces/{workspace}/git/push', [GitController::class, 'push']);
    Route::post('/workspaces/{workspace}/git/pull', [GitController::class, 'pull']);
    Route::post('/workspaces/{workspace}/git/release', [GitController::class, 'release']);

    Route::post('/workspaces/{workspace}/terminal', [TerminalController::class, 'run']);

    Route::get('/ai/models', [AiSessionController::class, 'models']);
    Route::get('/workspaces/{workspace}/ai/sessions', [AiSessionController::class, 'index']);
    Route::post('/workspaces/{workspace}/ai/sessions', [AiSessionController::class, 'store']);
    Route::get('/ai/sessions/{aiSession}', [AiSessionController::class, 'show']);
    Route::post('/ai/sessions/{aiSession}/messages', [AiSessionController::class, 'message']);
    Route::post('/ai/sessions/{aiSession}/stop', [AiSessionController::class, 'stop']);
    Route::delete('/ai/sessions/{aiSession}', [AiSessionController::class, 'destroy']);

    Route::get('/queue', QueueController::class);
    Route::get('/devices', [ConfigController::class, 'devices']);

    Route::get('/workspaces/{workspace}/configs', [ConfigController::class, 'index']);
    Route::post('/workspaces/{workspace}/configs', [ConfigController::class, 'store']);
    Route::post('/workspaces/{workspace}/configs/generate', [ConfigController::class, 'generate']);
    Route::post('/workspaces/{workspace}/configs/compare', [ConfigController::class, 'compare']);
    Route::post('/workspaces/{workspace}/configs/{snapshot}/apply', [ConfigController::class, 'apply']);
    Route::delete('/workspaces/{workspace}/configs/{snapshot}', [ConfigController::class, 'destroy']);

    Route::get('/workspaces/{workspace}/builds', [BuildController::class, 'index']);
    Route::post('/workspaces/{workspace}/builds', [BuildController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/builds/{build}', [BuildController::class, 'show']);
    Route::get('/builds/{build}/logs', [BuildController::class, 'logs']);
    Route::post('/builds/{build}/cancel', [BuildController::class, 'cancel']);
    Route::delete('/builds/{build}', [BuildController::class, 'destroy']);
});

Route::get('/builds/{build}/artifacts/{artifact}', [BuildController::class, 'download'])
    ->middleware('throttle:60,1');
