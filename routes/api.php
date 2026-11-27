<?php

use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\PersonalTokenController;
use App\Http\Controllers\SessionController;
use App\Http\Middleware\ActiveIdentity;
use App\Http\Middleware\RequireSpaSession;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [SessionController::class, 'login'])
        ->middleware([RequireSpaSession::class, 'throttle:identity.credentials']);

    Route::post('auth/token', [PersonalTokenController::class, 'store'])->middleware('throttle:identity.credentials');

    Route::middleware('throttle:identity.recovery')->group(function (): void {
        Route::post('auth/forgot-password', [PasswordRecoveryController::class, 'send']);
        Route::post('auth/reset-password', [PasswordRecoveryController::class, 'reset']);
    });

    Route::middleware(['auth:sanctum', ActiveIdentity::class])->group(function (): void {
        Route::post('auth/logout', [SessionController::class, 'logout'])->middleware(RequireSpaSession::class);
        Route::delete('auth/token', [PersonalTokenController::class, 'destroy']);
        Route::get('me', [SessionController::class, 'show']);
    });
});
