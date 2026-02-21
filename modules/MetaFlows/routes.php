<?php

use Illuminate\Support\Facades\Route;
use Modules\MetaFlows\Controllers\MetaFlowController;
use Modules\MetaFlows\Controllers\SetupController;

// Admin routes — manage the addon toggle from /admin/addons
Route::prefix('admin')->middleware(['auth:admin'])->group(function () {
    Route::post('/addons/setup/meta-flows', [SetupController::class, 'store']);
    Route::put('/addons/setup/meta-flows',  [SetupController::class, 'store']);
});

Route::middleware(['auth:user'])->prefix('/automation/meta-flows')->group(function () {
    Route::get('/', [MetaFlowController::class, 'index']);
    Route::post('/', [MetaFlowController::class, 'store']);

    // Returns all published flows for the current org — used by the chat flow picker
    Route::get('/list', [MetaFlowController::class, 'list']);

    // Bulk pull from Meta — must come before {uuid} routes
    Route::post('/pull', [MetaFlowController::class, 'pull']);

    // Per-flow action routes — must come before the {uuid} catch-all
    Route::post('/{uuid}/publish', [MetaFlowController::class, 'publish']);
    Route::post('/{uuid}/deprecate', [MetaFlowController::class, 'deprecate']);
    Route::post('/{uuid}/sync', [MetaFlowController::class, 'sync']);

    Route::get('/{uuid}', [MetaFlowController::class, 'view']);
    Route::put('/{uuid}', [MetaFlowController::class, 'update']);
    Route::delete('/{uuid}', [MetaFlowController::class, 'destroy']);
});
