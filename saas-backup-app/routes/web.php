<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PbsController;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/upload', [DashboardController::class, 'upload'])->name('upload');

Route::prefix('pbs')->group(function () {
    Route::get('/', [PbsController::class, 'index'])->name('pbs.index');
    Route::get('/api/env', [PbsController::class, 'getEnv']);
    Route::post('/api/env/save', [PbsController::class, 'saveEnv']);
    Route::post('/api/restic/init', [PbsController::class, 'initDatastore']);
    Route::post('/api/restic/snapshot', [PbsController::class, 'takeSnapshot']);
    Route::get('/api/restic/list', [PbsController::class, 'listSnapshots']);
    Route::post('/api/restic/restore', [PbsController::class, 'restoreSnapshot']);
});
