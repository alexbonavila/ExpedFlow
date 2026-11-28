<?php

use App\Http\Controllers\SambaController;
use App\Http\Controllers\SambaIncidentController;
use App\Http\Middleware\RequirePermission;
use App\Support\PermissionKey;
use Illuminate\Support\Facades\Route;

Route::prefix('expedients/{expedient}')->whereNumber('expedient')
    ->middleware([
        RequirePermission::class.':'.PermissionKey::ViewExpedients->value,
        RequirePermission::class.':'.PermissionKey::UploadCaseFiles->value,
    ])->group(function (): void {
        Route::post('storage/retry', [SambaController::class, 'retry']);
        Route::post('uploads', [SambaController::class, 'upload'])->middleware('throttle:samba.uploads');
    });

Route::prefix('incidents')->controller(SambaIncidentController::class)
    ->can(PermissionKey::ViewIncidents->value)->group(function (): void {
        Route::get('/', 'index');
        Route::get('{incident}', 'show')->whereNumber('incident');
    });
