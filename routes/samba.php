<?php

use App\Http\Controllers\SambaController;
use App\Http\Middleware\RequirePermission;
use App\Support\PermissionKey;
use Illuminate\Support\Facades\Route;

Route::prefix('expedients/{expedient}')->whereNumber('expedient')
    ->middleware([
        RequirePermission::class.':'.PermissionKey::ViewExpedients->value,
        RequirePermission::class.':'.PermissionKey::UploadCaseFiles->value,
    ])->group(function (): void {
        Route::post('storage/retry', [SambaController::class, 'retry']);
    });
