<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskAssigneeRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\PermissionKey;
use App\Support\TaskScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class TaskAssigneeController extends Controller
{
    public function __construct(private readonly TaskScope $scope, private readonly BusinessWrite $write) {}

    public function index(string $task): JsonResponse
    {
        $record = $this->scope->readTask(AuthorizationInput::identifier($task));

        return response()->json(['data' => $this->summaries($record)]);
    }

    public function candidates(string $task): JsonResponse
    {
        [$record, $case] = $this->scope->read(AuthorizationInput::identifier($task));
        Gate::authorize('assign', [$record, $case]);

        $users = User::query()->whereNull('disabled_at')->orderBy('name')->orderBy('id')->get(['id', 'name']);

        return response()->json(['data' => $users->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])]);
    }

    public function replace(TaskAssigneeRequest $request, string $task): JsonResponse
    {
        $id = AuthorizationInput::identifier($task);
        /** @var list<int> $desired */
        $desired = Arr::array($request->validated(), 'user_ids');
        $summaries = $this->write->run($request, PermissionKey::AssignTasks, function (User $actor) use ($id, $desired): array {
            [$record, $case] = $this->scope->lockActive($id, $actor);
            Gate::forUser($actor)->authorize('assign', [$record, $case]);

            $existing = DB::table('task_user')->where('task_id', $record->id)->orderBy('user_id')->lockForUpdate()
                ->pluck('user_id')->all();
            $users = User::query()->whereKey(array_unique([...$existing, ...$desired]))
                ->orderBy('id')->lockForUpdate()->get(['id', 'name', 'disabled_at'])->keyBy('id');

            foreach ($desired as $userId) {
                $user = $users->get($userId);
                if ($user === null || ($user->disabled_at !== null && ! in_array($userId, $existing, true))) {
                    throw ValidationException::withMessages(['user_ids' => __('tasks.task_assignee_invalid')]);
                }
            }

            $remove = array_diff($existing, $desired);
            $add = array_diff($desired, $existing);
            if ($remove !== []) {
                $record->assignees()->detach($remove);
            }
            if ($add !== []) {
                $record->assignees()->attach($add);
            }

            return $this->summaries($record);
        });

        return response()->json(['data' => $summaries]);
    }

    /** @return list<array{id: int, name: string, active: bool}> */
    private function summaries(Task $task): array
    {
        return array_values($task->assignees()->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.disabled_at'])->map(TaskResource::assignee(...))->all());
    }
}
