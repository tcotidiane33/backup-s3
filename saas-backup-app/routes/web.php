<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\bsController;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/upload', [DashboardController::class, 'upload'])->name('upload');

Route::prefix('bs')->group(function () {
    Route::get('/', [bsController::class, 'index'])->name('bs.index');
    Route::get('/api/env', [bsController::class, 'getEnv']);
    Route::post('/api/env/save', [bsController::class, 'saveEnv']);
    Route::post('/api/restic/init', [bsController::class, 'initDatastore']);
    Route::post('/api/restic/snapshot', [bsController::class, 'takeSnapshot']);
    Route::get('/api/restic/list', [bsController::class, 'listSnapshots']);
    Route::post('/api/restic/restore', [bsController::class, 'restoreSnapshot']);
});
