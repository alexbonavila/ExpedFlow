<?php

use App\Http\Controllers\TaskController;
use App\Http\Middleware\RequirePermission;
use App\Support\PermissionKey;
use Illuminate\Support\Facades\Route;

Route::middleware(RequirePermission::class.':'.PermissionKey::ViewExpedients->value)->group(function (): void {
    Route::post('expedients/{expedient}/tasks', [TaskController::class, 'store'])->whereNumber('expedient')
        ->middleware(RequirePermission::class.':'.PermissionKey::CreateTasks->value);

    Route::middleware(RequirePermission::class.':'.PermissionKey::ViewTasks->value)->group(function (): void {
        Route::get('expedients/{expedient}/tasks', [TaskController::class, 'index'])->whereNumber('expedient');
        Route::post('tasks/{task}/move', [TaskController::class, 'move'])->whereNumber('task')
            ->middleware(RequirePermission::class.':'.PermissionKey::ReorderTasks->value);
        Route::patch('tasks/{task}', [TaskController::class, 'update'])->whereNumber('task')
            ->middleware(RequirePermission::class.':'.PermissionKey::UpdateTasks->value);
        Route::get('tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');
    });
});
