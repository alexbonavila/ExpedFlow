<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskCalendarRequest;
use App\Http\Resources\TaskCalendarResource;
use App\Models\Task;
use App\Support\IdentityError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class TaskCalendarController extends Controller
{
    public function __invoke(TaskCalendarRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewCalendar', Task::class);
        $actor = $request->user() ?? throw new IdentityError(401, 'unauthenticated');
        $input = $request->validated();
        $tasks = Task::query()
            ->join('expedients', 'expedients.id', '=', 'tasks.expedient_id')
            ->whereNull('expedients.deleted_at')
            ->whereNotNull('tasks.scheduled_date')
            ->whereHas('assignees', fn (Builder $users): Builder => $users->whereKey($actor->id));
        if (isset($input['from'])) {
            $tasks->where('tasks.scheduled_date', '>=', $input['from']);
        }
        if (isset($input['to'])) {
            $tasks->where('tasks.scheduled_date', '<=', $input['to']);
        }

        return TaskCalendarResource::collection($tasks->orderBy('tasks.scheduled_date')->orderBy('tasks.scheduled_time')
            ->orderBy('tasks.id')->get(['tasks.id', 'tasks.expedient_id', 'tasks.name', 'tasks.scheduled_date', 'tasks.scheduled_time',
                'expedients.code as calendar_case_code', 'expedients.name as calendar_case_name']));
    }
}
