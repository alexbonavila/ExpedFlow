<?php

use App\Http\Controllers\ExpedientTypeCatalogController;
use App\Http\Controllers\StatusCatalogController;
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

Route::prefix('statuses')->controller(StatusCatalogController::class)->group(function (): void {
    Route::get('{status}', 'show')->whereNumber('status')
        ->middleware(RequirePermission::class.':'.PermissionKey::ViewStatuses->value);
    Route::middleware(RequirePermission::class.':'.PermissionKey::ManageStatuses->value)->group(function (): void {
        Route::post('/', 'store');
        Route::put('order', 'reorder');
        Route::patch('{status}', 'update')->whereNumber('status');
        Route::delete('{status}', 'destroy')->whereNumber('status');
    });
});
