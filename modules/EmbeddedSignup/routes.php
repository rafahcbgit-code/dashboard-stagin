<?php 

use Illuminate\Support\Facades\Route;

Route::post('/whatsapp/exchange-code', [Modules\EmbeddedSignup\Controllers\RegisterController::class, 'handleSignup'])
    ->middleware([
        'auth:user',
        'check.email.verification',
        'check.organization',
        'isTrial',
        'check.client.role',
    ]);

Route::prefix('admin')->middleware(['auth:admin'])->group(function () {
    Route::post('/addons/setup/embedded-signup', [Modules\EmbeddedSignup\Controllers\SetupController::class, 'store']);
    Route::put('/addons/setup/embedded-signup', [Modules\EmbeddedSignup\Controllers\SetupController::class, 'update']);
});
