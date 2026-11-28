<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskResource;
use App\Models\Expedient;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditTaskDescendants;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\IdentityError;
use App\Support\PermissionKey;
use App\Support\TaskOrdering;
use App\Support\TaskScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class TaskLifecycleController extends Controller
{
    public function __construct(
        private readonly TaskScope $scope,
        private readonly TaskOrdering $ordering,
        private readonly BusinessWrite $write,
        private readonly AuditRecorder $audit,
        private readonly AuditTaskDescendants $descendants,
    ) {}

    public function trash(string $expedient): AnonymousResourceCollection
    {
        $case = $this->trashCase($expedient);

        return TaskResource::collection($this->independentTrash($case)->with(TaskResource::RELATIONS)
            ->orderByDesc('deleted_at')->orderBy('id')->get());
    }

    public function showTrashed(string $expedient, string $task): TaskResource
    {
        $case = $this->trashCase($expedient);
        $record = $this->independentTrash($case)->with(TaskResource::RELATIONS)
            ->findOrFail(AuthorizationInput::identifier($task));
        Gate::authorize('viewTrash', [$record, $case]);

        return new TaskResource($record);
    }

    public function destroy(Request $request, string $task): JsonResponse
    {
        $id = AuthorizationInput::identifier($task);
        $this->write->run($request, PermissionKey::DeleteTasks, function (User $actor, AuditContext $context) use ($id): void {
            [$record, $case] = $this->scope->lockActive($id, $actor);
            Gate::forUser($actor)->authorize('delete', [$record, $case]);
            if (! $record->delete()) {
                throw new RuntimeException('Task deletion did not complete.');
            }
            $this->audit->record($context, AuditEvent::TaskDeleted, $record, ['state' => 'active'], ['state' => 'trashed'], $case);
        });

        return response()->json(['message' => __('tasks.task_deleted'), 'code' => 'task_deleted']);
    }

    public function restore(Request $request, string $task): TaskResource
    {
        $id = AuthorizationInput::identifier($task);
        $record = $this->write->run($request, PermissionKey::RestoreTasks, function (User $actor, AuditContext $context) use ($id): Task {
            $case = $this->scope->lockParent($id, trashed: true);
            $observed = $this->independentTrash($case)->findOrFail($id, ['status_id'])->status_id;
            $this->ordering->lockStatuses($observed);
            $column = $case->tasks()->withTrashed()->where('status_id', $observed)
                ->where(fn (Builder $query): Builder => $query->whereNull('deleted_at')->orWhereKey($id))
                ->orderBy('id')->lockForUpdate()->get();
            $record = $column->find($id) ?? throw new IdentityError(404, 'not_found');
            if (! $record->trashed() || $record->deleted_by_expedient) {
                throw new IdentityError(404, 'not_found');
            }
            Gate::forUser($actor)->authorize('restore', [$record, $case]);

            $previous = $record->position;
            $occupied = $column->reject(fn (Task $task): bool => $task->trashed())->keyBy('position');
            if ($occupied->has($record->position)) {
                $position = 1;
                while ($occupied->has($position)) {
                    $position++;
                }
                $record->forceFill(['position' => $position]);
            }
            if (! $record->restore()) {
                throw new RuntimeException('Task restoration did not complete.');
            }
            $this->audit->record($context, AuditEvent::TaskRestored, $record,
                ['state' => 'trashed', 'position' => $previous],
                ['state' => 'active', 'position' => $record->position], $case);

            return $record->load(TaskResource::RELATIONS);
        });

        return new TaskResource($record);
    }

    public function forceDelete(Request $request, string $task): JsonResponse
    {
        $id = AuthorizationInput::identifier($task);
        $this->write->run($request, PermissionKey::ForceDeleteTasks, function (User $actor, AuditContext $context) use ($id): void {
            $case = $this->scope->lockParent($id, trashed: true);
            $record = $this->independentTrash($case)->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('forceDelete', [$record, $case]);
            $this->descendants->record($context, $case, $record);
            if (! $record->forceDelete()) {
                throw new RuntimeException('Permanent task deletion did not complete.');
            }
            $this->audit->record($context, AuditEvent::TaskForceDeleted, $record, ['state' => 'trashed'], ['state' => 'deleted'], $case);
        });

        return response()->json(['message' => __('tasks.task_force_deleted'), 'code' => 'task_force_deleted']);
    }

    private function trashCase(string $expedient): Expedient
    {
        $this->scope->authorizeTrash(PermissionKey::RestoreTasks, PermissionKey::ForceDeleteTasks);
        $case = Expedient::query()->findOrFail(AuthorizationInput::identifier($expedient));
        Gate::authorize('viewAnyTrash', [Task::class, $case]);

        return $case;
    }

    /** @return Builder<Task> */
    private function independentTrash(Expedient $case): Builder
    {
        return Task::onlyTrashed()->where('expedient_id', $case->id)->where('deleted_by_expedient', false);
    }
}
