<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskChecklistOrderRequest;
use App\Http\Requests\TaskChecklistRequest;
use App\Http\Resources\TaskChecklistItemResource;
use App\Models\Expedient;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditSubject;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\IdentityError;
use App\Support\PermissionKey;
use App\Support\TaskOrdering;
use App\Support\TaskScope;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class TaskChecklistController extends Controller
{
    public function __construct(
        private readonly TaskScope $scope,
        private readonly BusinessWrite $write,
        private readonly TaskOrdering $ordering,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(string $task): AnonymousResourceCollection
    {
        $record = $this->scope->readTask(AuthorizationInput::identifier($task));

        return TaskChecklistItemResource::collection($record->checklistItems()->orderBy('position')->orderBy('id')->get());
    }

    public function show(string $task, string $item): TaskChecklistItemResource
    {
        $record = $this->scope->readTask(AuthorizationInput::identifier($task));

        return new TaskChecklistItemResource($record->checklistItems()->findOrFail(AuthorizationInput::identifier($item)));
    }

    public function store(TaskChecklistRequest $request, string $task): JsonResponse
    {
        $input = $request->validated();
        $item = $this->manage($request, $task, null, function (Task $record, ?TaskChecklistItem $existing, Expedient $case, AuditContext $context) use ($input): TaskChecklistItem {
            $items = $this->lockItems($record);
            $position = $this->ordering->increment($items->max('position') ?? 0);
            $item = $record->checklistItems()->forceCreate(['position' => $position] + $input + ['completed' => false]);
            if (! $item->exists) {
                throw new RuntimeException('Checklist item creation did not complete.');
            }

            $this->audit->record($context, AuditEvent::ChecklistCreated, $item, after: AuditSubject::Checklist->snapshot($item), case: $case);

            return $item;
        });

        return (new TaskChecklistItemResource($item))->response()->setStatusCode(201);
    }

    public function update(TaskChecklistRequest $request, string $task, string $item): TaskChecklistItemResource
    {
        $input = $request->validated();
        $record = $this->manage($request, $task, $item, function (Task $task, ?TaskChecklistItem $item, Expedient $case, AuditContext $context) use ($input): TaskChecklistItem {
            $item ??= throw new RuntimeException('The locked checklist item is missing.');
            $before = AuditSubject::Checklist->snapshot($item);
            if (! $item->forceFill($input)->save()) {
                throw new RuntimeException('Checklist item update did not complete.');
            }
            $this->audit->record($context, AuditEvent::ChecklistUpdated, $item, $before, AuditSubject::Checklist->snapshot($item), $case,
                ['task_id' => $task->id]);

            return $item;
        });

        return new TaskChecklistItemResource($record);
    }

    public function destroy(Request $request, string $task, string $item): JsonResponse
    {
        $this->manage($request, $task, $item, function (Task $task, ?TaskChecklistItem $item, Expedient $case, AuditContext $context): void {
            $item ??= throw new RuntimeException('The locked checklist item is missing.');
            if ($item->delete() !== true) {
                throw new RuntimeException('Checklist item deletion did not complete.');
            }
            $this->audit->record($context, AuditEvent::ChecklistDeleted, $item, AuditSubject::Checklist->snapshot($item), case: $case,
                metadata: ['task_id' => $task->id]);
        });

        return response()->json(['message' => __('tasks.task_checklist_deleted'), 'code' => 'task_checklist_deleted']);
    }

    public function reorder(TaskChecklistOrderRequest $request, string $task): AnonymousResourceCollection
    {
        /** @var list<int> $ids */
        $ids = Arr::array($request->validated(), 'item_ids');
        $ordered = $this->manage($request, $task, null, function (Task $record, ?TaskChecklistItem $existing, Expedient $case, AuditContext $context) use ($ids): array {
            $items = $this->lockItems($record)->keyBy('id');
            $membership = $ids;
            sort($membership);
            if ($membership !== array_values($items->modelKeys())) {
                throw new IdentityError(409, 'task_checklist_membership_changed', 'tasks');
            }

            $ordered = [];
            foreach ($ids as $index => $id) {
                $item = $items->get($id) ?? throw new RuntimeException('The locked checklist item is missing.');
                $before = $item->position;
                if (! $item->forceFill(['position' => $index + 1])->save()) {
                    throw new RuntimeException('Checklist reorder did not complete.');
                }
                $this->audit->record($context, AuditEvent::ChecklistReordered, $item,
                    ['position' => $before], ['position' => $item->position], $case, ['task_id' => $record->id]);
                $ordered[] = $item;
            }

            return $ordered;
        });

        return TaskChecklistItemResource::collection($ordered);
    }

    /** @return Collection<int, TaskChecklistItem> */
    private function lockItems(Task $record): Collection
    {
        return $record->checklistItems()->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * @template TResult
     *
     * @param  Closure(Task, ?TaskChecklistItem, Expedient, AuditContext): TResult  $operation
     * @return TResult
     */
    private function manage(Request $request, string $task, ?string $item, Closure $operation): mixed
    {
        $taskId = AuthorizationInput::identifier($task);
        $itemId = $item === null ? null : AuthorizationInput::identifier($item);

        return $this->write->run($request, PermissionKey::ManageTaskChecklist, function (User $actor, AuditContext $context) use ($taskId, $itemId, $operation): mixed {
            [$record, $case] = $this->scope->lockActive($taskId, $actor);
            $item = $itemId === null ? null : $record->checklistItems()->lockForUpdate()->findOrFail($itemId);
            Gate::forUser($actor)->authorize('manage', [TaskChecklistItem::class, $record, $case, $item]);

            return $operation($record, $item, $case, $context);
        });
    }
}
