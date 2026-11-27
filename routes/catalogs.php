<?php

use App\Http\Controllers\ExpedientTypeCatalogController;
use App\Http\Middleware\RequirePermission;
use App\Support\PermissionKey;
use Illuminate\Support\Facades\Route;

Route::prefix('expedient-types')->controller(ExpedientTypeCatalogController::class)->group(function (): void {
    Route::get('{type}', 'show')->whereNumber('type')
        ->middleware(RequirePermission::class.':'.PermissionKey::ViewExpedientTypes->value);
    Route::middleware(RequirePermission::class.':'.PermissionKey::ManageExpedientTypes->value)->group(function (): void {
        Route::post('/', 'store');
        Route::patch('{type}', 'update')->whereNumber('type');
        Route::delete('{type}', 'destroy')->whereNumber('type');
    });
});
