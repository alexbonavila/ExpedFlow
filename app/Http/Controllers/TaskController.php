<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskMoveRequest;
use App\Http\Requests\TaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Expedient;
use App\Models\Task;
use App\Models\User;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\EffectivePermissions;
use App\Support\ExpedientLifecycle;
use App\Support\IdentityError;
use App\Support\PermissionKey;
use App\Support\TaskOrdering;
use App\Support\TaskScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class TaskController extends Controller
{
    public function __construct(
        private readonly TaskScope $scope,
        private readonly BusinessWrite $write,
        private readonly ExpedientLifecycle $lifecycle,
        private readonly TaskOrdering $ordering,
    ) {}

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

    public function store(TaskRequest $request, string $expedient): JsonResponse
    {
        $id = AuthorizationInput::identifier($expedient);
        $input = $request->validated();
        $record = $this->write->run($request, PermissionKey::CreateTasks, function (User $actor) use ($id, $input): Task {
            $case = $this->lifecycle->lockParent($id);
            Gate::forUser($actor)->authorize('create', [Task::class, $case]);
            $status = Arr::integer($input, 'status_id');
            $this->ordering->lockStatuses($status);
            $fields = $this->schedule(Arr::except($input, 'status_id'));

            return $this->ordering->append($case, $status, $fields)->refresh()->load(TaskResource::RELATIONS);
        });

        return (new TaskResource($record))->response()->setStatusCode(201);
    }

    public function update(TaskRequest $request, string $task): TaskResource
    {
        $id = AuthorizationInput::identifier($task);
        $input = $request->validated();
        $record = $this->write->run($request, PermissionKey::UpdateTasks, function (User $actor) use ($id, $input): Task {
            $case = $this->scope->lockParent($id);
            $observed = $case->tasks()->findOrFail($id, ['status_id'])->status_id;
            $destination = Arr::integer($input, 'status_id', $observed);
            if ($destination !== $observed) {
                Gate::forUser($actor)->allowIf(app(EffectivePermissions::class)->allows($actor, PermissionKey::ReorderTasks));
            }
            $this->ordering->lockStatuses($observed, $destination);
            $gate = Gate::forUser($actor);
            $columns = $this->ordering->lockColumns($case, $observed, $destination);
            $record = $columns->find($id) ?? throw new IdentityError(404, 'not_found');
            $gate->authorize('update', [$record, $case]);
            if ($record->status_id !== $observed) {
                throw new IdentityError(409, 'task_status_changed');
            }

            $fields = $this->schedule(Arr::except($input, 'status_id'), $record);
            if ($destination !== $observed) {
                $gate->authorize('reorder', [$record, $case]);
                $this->ordering->move($record, $columns, $destination, null);
            }
            if (! $record->forceFill($fields)->save()) {
                throw new RuntimeException('Task update did not complete.');
            }

            return $record->refresh()->load(TaskResource::RELATIONS);
        });

        return new TaskResource($record);
    }

    public function move(TaskMoveRequest $request, string $task): TaskResource
    {
        $id = AuthorizationInput::identifier($task);
        $input = $request->validated();
        $destination = Arr::integer($input, 'status_id');
        $position = isset($input['position']) ? Arr::integer($input, 'position') : null;
        $record = $this->write->run($request, PermissionKey::ReorderTasks, function (User $actor) use ($id, $destination, $position): Task {
            $case = $this->scope->lockParent($id);
            $observed = $case->tasks()->findOrFail($id, ['status_id'])->status_id;
            $this->ordering->lockStatuses($observed, $destination);
            $columns = $this->ordering->lockColumns($case, $observed, $destination);
            $record = $columns->find($id) ?? throw new IdentityError(404, 'not_found');
            Gate::forUser($actor)->authorize('reorder', [$record, $case]);
            if ($record->status_id !== $observed) {
                throw new IdentityError(409, 'task_status_changed');
            }

            $this->ordering->move($record, $columns, $destination, $position);

            return $record->load(TaskResource::RELATIONS);
        });

        return new TaskResource($record);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function schedule(array $fields, ?Task $task = null): array
    {
        $date = array_key_exists('scheduled_date', $fields) ? $fields['scheduled_date'] : $task?->scheduled_date;
        $time = array_key_exists('scheduled_time', $fields) ? $fields['scheduled_time'] : $task?->scheduled_time;
        if ($date === null && $time !== null) {
            throw ValidationException::withMessages(['scheduled_time' => __('tasks.task_time_requires_date')]);
        }

        return $fields;
    }
}
