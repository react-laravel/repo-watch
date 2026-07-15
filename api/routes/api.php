<?php

use App\Http\Controllers\Api\Auth\SsoController;
use App\Http\Controllers\Api\GithubWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/auth/csrf', [SsoController::class, 'csrf']);
    Route::post('/auth/exchange', [SsoController::class, 'exchange']);

    Route::post('/github/webhooks/repo-watch', [GithubWebhookController::class, 'repoWatch']);

    Route::middleware('repo-watch.auth')->group(function (): void {
        Route::get('/user', [SsoController::class, 'user']);
        Route::post('/auth/logout', [SsoController::class, 'logout']);

        require base_path('routes/api/repo-watch.php');
    });
});
