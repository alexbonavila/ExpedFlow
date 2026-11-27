<?php

use App\Http\Controllers\LocalePreferenceController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\PersonalTokenController;
use App\Http\Controllers\RoleManagementController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\UserManagementController;
use App\Http\Middleware\ActiveIdentity;
use App\Http\Middleware\RequireAdministrator;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireSpaSession;
use App\Support\PermissionKey;
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
        Route::patch('me/locale', [LocalePreferenceController::class, 'update']);

        Route::middleware(RequirePermission::class.':'.PermissionKey::ManageRoles->value)
            ->controller(RoleManagementController::class)->group(function (): void {
                Route::get('permissions', 'permissions');
                Route::get('roles', 'index');
                Route::post('roles', 'store');
                Route::get('roles/{role}', 'show')->whereNumber('role');
                Route::patch('roles/{role}', 'update')->whereNumber('role');
                Route::put('roles/{role}/permissions', 'replacePermissions')->whereNumber('role');
                Route::delete('roles/{role}', 'destroy')->whereNumber('role');
            });

        Route::prefix('users')->middleware(RequireAdministrator::class)->controller(UserManagementController::class)->group(function (): void {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('{user}', 'show')->whereNumber('user');
            Route::patch('{user}', 'update')->whereNumber('user');
            Route::patch('{user}/password', 'password')->whereNumber('user');
            Route::post('{user}/disable', 'disable')->whereNumber('user');
            Route::post('{user}/enable', 'enable')->whereNumber('user');
        });
    });
});
