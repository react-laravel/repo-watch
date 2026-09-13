<?php

use App\Http\Controllers\Api\Tools\DependencyChangeController;
use App\Http\Controllers\Api\Tools\PackageAdvisoryController;
use App\Http\Controllers\Api\Tools\RepoWatchNotificationController;
use App\Http\Controllers\Api\Tools\RepositoryWatchController;
use App\Http\Controllers\Api\Tools\WatchedRepositoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('repo-watch')->group(function (): void {
    Route::post('/preview', [RepositoryWatchController::class, 'preview']);
    Route::get('/packages', [RepositoryWatchController::class, 'index']);
    Route::post('/packages', [RepositoryWatchController::class, 'store']);
    Route::delete('/packages', [RepositoryWatchController::class, 'destroyBatch']);
    Route::post('/packages/{watchedPackage}/refresh', [RepositoryWatchController::class, 'refresh']);
    Route::delete('/packages/{watchedPackage}', [RepositoryWatchController::class, 'destroy']);

    Route::get('/repositories', [WatchedRepositoryController::class, 'index']);
    Route::post('/repositories', [WatchedRepositoryController::class, 'store']);
    Route::post('/repositories/bulk', [WatchedRepositoryController::class, 'storeBulk']);
    Route::post('/repositories/scan-unhealthy', [WatchedRepositoryController::class, 'scanUnhealthy']);
    Route::get('/repositories/{watchedRepository}', [WatchedRepositoryController::class, 'show']);
    Route::delete('/repositories/{watchedRepository}', [WatchedRepositoryController::class, 'destroy']);
    Route::post('/repositories/{watchedRepository}/scan', [WatchedRepositoryController::class, 'scan']);
    Route::get('/repositories/{watchedRepository}/changes', [WatchedRepositoryController::class, 'changes']);

    Route::get('/dependency-changes', [DependencyChangeController::class, 'index']);
    Route::get('/advisories', [PackageAdvisoryController::class, 'index']);

    Route::get('/notifications', [RepoWatchNotificationController::class, 'index']);
    Route::post('/notifications/read-all', [RepoWatchNotificationController::class, 'markAllRead']);
    Route::post('/notifications/{repoWatchNotification}/read', [RepoWatchNotificationController::class, 'markRead']);
});
