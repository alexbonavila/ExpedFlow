<?php

use App\Http\Controllers\ExpedientTagController;
use App\Http\Controllers\ExpedientTypeCatalogController;
use App\Http\Controllers\StatusCatalogController;
use App\Http\Controllers\TagCatalogController;
use App\Http\Controllers\TaskTemplateController;
use App\Http\Controllers\TemplateChecklistController;
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

Route::prefix('expedient-types/{type}/task-templates')->whereNumber('type')
    ->middleware(RequirePermission::class.':'.PermissionKey::ViewExpedientTypes->value)
    ->group(function (): void {
        Route::middleware(RequirePermission::class.':'.PermissionKey::ViewTaskTemplates->value)->group(function (): void {
            Route::get('/', [TaskTemplateController::class, 'index']);
            Route::get('{template}', [TaskTemplateController::class, 'show'])->whereNumber('template');
            Route::get('{template}/checklist', [TemplateChecklistController::class, 'index'])->whereNumber('template');
            Route::get('{template}/checklist/{item}', [TemplateChecklistController::class, 'show'])->whereNumber(['template', 'item']);
        });

        Route::middleware(RequirePermission::class.':'.PermissionKey::ManageTaskTemplates->value)->group(function (): void {
            Route::post('/', [TaskTemplateController::class, 'store']);
            Route::put('order', [TaskTemplateController::class, 'reorder']);
            Route::patch('{template}', [TaskTemplateController::class, 'update'])->whereNumber('template');
            Route::delete('{template}', [TaskTemplateController::class, 'destroy'])->whereNumber('template');
            Route::post('{template}/checklist', [TemplateChecklistController::class, 'store'])->whereNumber('template');
            Route::put('{template}/checklist/order', [TemplateChecklistController::class, 'reorder'])->whereNumber('template');
            Route::patch('{template}/checklist/{item}', [TemplateChecklistController::class, 'update'])->whereNumber(['template', 'item']);
            Route::delete('{template}/checklist/{item}', [TemplateChecklistController::class, 'destroy'])->whereNumber(['template', 'item']);
        });
    });

Route::prefix('tags')->controller(TagCatalogController::class)->group(function (): void {
    Route::middleware(RequirePermission::class.':'.PermissionKey::ViewTags->value)->group(function (): void {
        Route::get('/', 'index');
        Route::get('{tag}', 'show')->whereNumber('tag');
    });

    Route::middleware(RequirePermission::class.':'.PermissionKey::ManageTags->value)->group(function (): void {
        Route::post('/', 'store');
        Route::patch('{tag}', 'update')->whereNumber('tag');
        Route::delete('{tag}', 'destroy')->whereNumber('tag');
    });
});

Route::prefix('expedients/{expedient}/tags')->whereNumber('expedient')->controller(ExpedientTagController::class)
    ->middleware(RequirePermission::class.':'.PermissionKey::ViewExpedients->value)->group(function (): void {
        Route::get('/', 'index')->middleware(RequirePermission::class.':'.PermissionKey::ViewTags->value);
        Route::put('/', 'replace')->middleware(RequirePermission::class.':'.PermissionKey::AssociateTags->value);
    });
