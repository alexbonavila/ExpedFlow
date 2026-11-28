<?php

use App\Http\Controllers\TaskAssigneeController;
use App\Http\Controllers\TaskCalendarController;
use App\Http\Controllers\TaskChecklistController;
use App\Http\Controllers\TaskCommentController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskLifecycleController;
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
        Route::get('tasks/{task}/assignees', [TaskAssigneeController::class, 'index'])->whereNumber('task');
        Route::put('tasks/{task}/assignees', [TaskAssigneeController::class, 'replace'])->whereNumber('task')
            ->middleware(RequirePermission::class.':'.PermissionKey::AssignTasks->value);
        Route::get('tasks/{task}/assignee-candidates', [TaskAssigneeController::class, 'candidates'])->whereNumber('task')
            ->middleware(RequirePermission::class.':'.PermissionKey::AssignTasks->value);
        Route::prefix('tasks/{task}/comments')->whereNumber('task')->controller(TaskCommentController::class)
            ->group(function (): void {
                Route::get('/', 'index');
                Route::get('trash', 'trash');
                Route::get('trash/{comment}', 'showTrashed')->whereNumber('comment');
                Route::get('{comment}', 'show')->whereNumber('comment');
                Route::post('/', 'store')->middleware(RequirePermission::class.':'.PermissionKey::CreateTaskComments->value);
                Route::patch('{comment}', 'update')->whereNumber('comment')
                    ->middleware(RequirePermission::class.':'.PermissionKey::UpdateTaskComments->value);
                Route::delete('{comment}', 'destroy')->whereNumber('comment')
                    ->middleware(RequirePermission::class.':'.PermissionKey::DeleteTaskComments->value);
                Route::post('{comment}/restore', 'restore')->whereNumber('comment')
                    ->middleware(RequirePermission::class.':'.PermissionKey::RestoreTaskComments->value);
                Route::post('{comment}/force-delete', 'forceDelete')->whereNumber('comment')
                    ->middleware(RequirePermission::class.':'.PermissionKey::ForceDeleteTaskComments->value);
            });
        Route::prefix('tasks/{task}/checklist')->whereNumber('task')->controller(TaskChecklistController::class)
            ->group(function (): void {
                Route::get('/', 'index');
                Route::get('{item}', 'show')->whereNumber('item');
                Route::middleware(RequirePermission::class.':'.PermissionKey::ManageTaskChecklist->value)->group(function (): void {
                    Route::post('/', 'store');
                    Route::put('order', 'reorder');
                    Route::patch('{item}', 'update')->whereNumber('item');
                    Route::delete('{item}', 'destroy')->whereNumber('item');
                });
            });
        Route::get('calendar/tasks', TaskCalendarController::class)
            ->middleware(RequirePermission::class.':'.PermissionKey::ViewCalendar->value);
        Route::get('expedients/{expedient}/tasks/trash', [TaskLifecycleController::class, 'trash'])->whereNumber('expedient');
        Route::get('expedients/{expedient}/tasks/trash/{task}', [TaskLifecycleController::class, 'showTrashed'])
            ->whereNumber(['expedient', 'task']);
        Route::delete('tasks/{task}', [TaskLifecycleController::class, 'destroy'])->whereNumber('task')
            ->middleware(RequirePermission::class.':'.PermissionKey::DeleteTasks->value);
        Route::post('tasks/{task}/restore', [TaskLifecycleController::class, 'restore'])->whereNumber('task')
            ->middleware(RequirePermission::class.':'.PermissionKey::RestoreTasks->value);
        Route::post('tasks/{task}/force-delete', [TaskLifecycleController::class, 'forceDelete'])->whereNumber('task')
            ->middleware(RequirePermission::class.':'.PermissionKey::ForceDeleteTasks->value);
        Route::get('tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');
    });
});
