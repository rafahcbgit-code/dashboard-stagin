<?php

use Illuminate\Support\Facades\Route;
use Modules\MetaFlows\Controllers\GoogleOAuthController;
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

    // Google Sheets OAuth + status — must come before {uuid} wildcard routes
    Route::get('/google/status',       [MetaFlowController::class,   'googleStatus']);
    Route::get('/google/connect',       [GoogleOAuthController::class, 'connect']);
    Route::get('/google/callback',      [GoogleOAuthController::class, 'callback']);
    Route::delete('/google/disconnect', [GoogleOAuthController::class, 'disconnect']);

    // Per-flow action routes — must come before the {uuid} catch-all
    Route::post('/{uuid}/publish',   [MetaFlowController::class, 'publish']);
    Route::post('/{uuid}/deprecate', [MetaFlowController::class, 'deprecate']);
    Route::post('/{uuid}/sync',      [MetaFlowController::class, 'sync']);
    Route::get('/{uuid}/responses/export', [MetaFlowController::class, 'exportResponses']);
    Route::get('/{uuid}/responses',  [MetaFlowController::class, 'responses']);

    // Google Sheets per-flow routes
    Route::get('/{uuid}/flow-fields',  [MetaFlowController::class, 'getFlowFields']);
    Route::get('/{uuid}/sheet-headers', [MetaFlowController::class, 'getSheetHeaders']);
    Route::put('/{uuid}/sheet-config',  [MetaFlowController::class, 'saveSheetConfig']);

    // Post-submission settings (reply message, interactive reply, email notification)
    Route::put('/{uuid}/submission-settings', [MetaFlowController::class, 'saveSubmissionSettings']);

    Route::get('/{uuid}', [MetaFlowController::class, 'view']);
    Route::put('/{uuid}', [MetaFlowController::class, 'update']);
    Route::delete('/{uuid}', [MetaFlowController::class, 'destroy']);
});
