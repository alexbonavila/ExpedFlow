<?php

use App\Http\Controllers\ExpedientController;
use App\Http\Middleware\RequirePermission;
use App\Models\Expedient;
use App\Support\PermissionKey;
use Illuminate\Support\Facades\Route;

Route::prefix('expedients')->controller(ExpedientController::class)->group(function (): void {
    Route::get('/', 'index')->can('viewAny', Expedient::class);
    Route::post('/', 'store')->middleware(RequirePermission::class.':'.PermissionKey::CreateExpedients->value);
    Route::get('trash', 'trash')->can('viewAnyTrash', Expedient::class);
    Route::get('trash/{expedient}', 'showTrashed')->whereNumber('expedient')->can('viewAnyTrash', Expedient::class);
    Route::get('{expedient}', 'show')->whereNumber('expedient')
        ->middleware(RequirePermission::class.':'.PermissionKey::ViewExpedients->value);
    Route::patch('{expedient}', 'update')->whereNumber('expedient')
        ->middleware(RequirePermission::class.':'.PermissionKey::UpdateExpedients->value);
    Route::delete('{expedient}', 'destroy')->whereNumber('expedient')
        ->middleware(RequirePermission::class.':'.PermissionKey::DeleteExpedients->value);
    Route::post('{expedient}/restore', 'restore')->whereNumber('expedient')
        ->middleware(RequirePermission::class.':'.PermissionKey::RestoreExpedients->value);
    Route::post('{expedient}/force-delete', 'forceDelete')->whereNumber('expedient')
        ->middleware(RequirePermission::class.':'.PermissionKey::ForceDeleteExpedients->value);
});
