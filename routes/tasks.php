<?php

use App\Http\Controllers\TaskController;
use App\Http\Middleware\RequirePermission;
use App\Support\PermissionKey;
use Illuminate\Support\Facades\Route;

Route::middleware(RequirePermission::class.':'.PermissionKey::ViewExpedients->value)->group(function (): void {
    Route::middleware(RequirePermission::class.':'.PermissionKey::ViewTasks->value)->group(function (): void {
        Route::get('expedients/{expedient}/tasks', [TaskController::class, 'index'])->whereNumber('expedient');
        Route::get('tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');
    });
});
