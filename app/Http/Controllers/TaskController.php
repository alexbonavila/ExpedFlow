<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskResource;
use App\Models\Expedient;
use App\Models\Task;
use App\Support\AuthorizationInput;
use App\Support\TaskScope;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class TaskController extends Controller
{
    public function __construct(private readonly TaskScope $scope) {}

    public function index(string $expedient): AnonymousResourceCollection
    {
        $case = Expedient::query()->findOrFail(AuthorizationInput::identifier($expedient));
        Gate::authorize('viewAny', [Task::class, $case]);

        return TaskResource::collection($case->tasks()->with(TaskResource::RELATIONS)
            ->join('statuses', 'statuses.id', '=', 'tasks.status_id')
            ->orderBy('statuses.position')->orderBy('statuses.id')->orderBy('tasks.position')->orderBy('tasks.id')
            ->get(['tasks.*']));
    }

    public function show(string $task): TaskResource
    {
        [$record] = $this->scope->read(AuthorizationInput::identifier($task));

        return new TaskResource($record->load(TaskResource::RELATIONS));
    }
}
