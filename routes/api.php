<?php

use App\Http\Controllers\SessionController;
use App\Http\Middleware\ActiveIdentity;
use App\Http\Middleware\RequireSpaSession;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [SessionController::class, 'login'])
        ->middleware([RequireSpaSession::class, 'throttle:identity.credentials']);

    Route::middleware(['auth:sanctum', ActiveIdentity::class])->group(function (): void {
        Route::post('auth/logout', [SessionController::class, 'logout'])->middleware(RequireSpaSession::class);
        Route::get('me', [SessionController::class, 'show']);
    });
});
