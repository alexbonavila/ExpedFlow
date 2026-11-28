<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskCommentRequest;
use App\Http\Resources\TaskCommentResource;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\AuthorizationInput;
use App\Support\BusinessWrite;
use App\Support\PermissionKey;
use App\Support\TaskScope;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class TaskCommentController extends Controller
{
    public function __construct(private readonly TaskScope $scope, private readonly BusinessWrite $write, private readonly AuditRecorder $audit) {}

    public function index(string $task): AnonymousResourceCollection
    {
        $record = $this->scope->readTask(AuthorizationInput::identifier($task));

        return TaskCommentResource::collection($record->comments()->with(TaskCommentResource::AUTHOR)
            ->orderBy('created_at')->orderBy('id')->get());
    }

    public function show(string $task, string $comment): TaskCommentResource
    {
        [$record, $case] = $this->scope->read(AuthorizationInput::identifier($task));
        $entry = $record->comments()->with(TaskCommentResource::AUTHOR)->findOrFail(AuthorizationInput::identifier($comment));
        Gate::authorize('view', [$entry, $record, $case]);

        return new TaskCommentResource($entry);
    }

    public function trash(string $task): AnonymousResourceCollection
    {
        $this->scope->authorizeTrash(PermissionKey::RestoreTaskComments, PermissionKey::ForceDeleteTaskComments);
        [$record, $case] = $this->scope->read(AuthorizationInput::identifier($task));
        Gate::authorize('viewAnyTrash', [TaskComment::class, $record, $case]);

        return TaskCommentResource::collection($record->comments()->onlyTrashed()->with(TaskCommentResource::AUTHOR)
            ->orderByDesc('deleted_at')->orderBy('id')->get());
    }

    public function showTrashed(string $task, string $comment): TaskCommentResource
    {
        $this->scope->authorizeTrash(PermissionKey::RestoreTaskComments, PermissionKey::ForceDeleteTaskComments);
        [$record, $case] = $this->scope->read(AuthorizationInput::identifier($task));
        $entry = $record->comments()->onlyTrashed()->with(TaskCommentResource::AUTHOR)
            ->findOrFail(AuthorizationInput::identifier($comment));
        Gate::authorize('viewTrash', [$entry, $record, $case]);

        return new TaskCommentResource($entry);
    }

    public function store(TaskCommentRequest $request, string $task): JsonResponse
    {
        $id = AuthorizationInput::identifier($task);
        $body = Arr::string($request->validated(), 'body');
        $entry = $this->write->run($request, PermissionKey::CreateTaskComments, function (User $actor, AuditContext $context) use ($id, $body): TaskComment {
            [$record, $case] = $this->scope->lockActive($id, $actor);
            Gate::forUser($actor)->authorize('create', [TaskComment::class, $record, $case]);
            $entry = $record->comments()->forceCreate(['author_user_id' => $actor->id, 'body' => $body]);
            if (! $entry->exists) {
                throw new RuntimeException('Comment creation did not complete.');
            }

            $this->audit->record($context, AuditEvent::CommentCreated, $entry,
                after: ['task_id' => $record->id, 'body' => true, 'state' => 'active'], case: $case,
                metadata: ['task_id' => $record->id, 'author_id' => $entry->author_user_id]);

            return $entry->load(TaskCommentResource::AUTHOR);
        });

        return (new TaskCommentResource($entry))->response()->setStatusCode(201);
    }

    public function update(TaskCommentRequest $request, string $task, string $comment): TaskCommentResource
    {
        $body = Arr::string($request->validated(), 'body');
        $entry = $this->change($request, $task, $comment, PermissionKey::UpdateTaskComments, 'update',
            fn (TaskComment $entry): bool => $entry->forceFill(['body' => $body])->save());

        return new TaskCommentResource($entry->load(TaskCommentResource::AUTHOR));
    }

    public function destroy(Request $request, string $task, string $comment): JsonResponse
    {
        $this->change($request, $task, $comment, PermissionKey::DeleteTaskComments, 'delete',
            fn (TaskComment $entry): ?bool => $entry->delete());

        return response()->json(['message' => __('tasks.task_comment_deleted'), 'code' => 'task_comment_deleted']);
    }

    public function restore(Request $request, string $task, string $comment): TaskCommentResource
    {
        $entry = $this->change($request, $task, $comment, PermissionKey::RestoreTaskComments, 'restore',
            fn (TaskComment $entry): bool => $entry->restore(), trashed: true);

        return new TaskCommentResource($entry->load(TaskCommentResource::AUTHOR));
    }

    public function forceDelete(Request $request, string $task, string $comment): JsonResponse
    {
        $this->change($request, $task, $comment, PermissionKey::ForceDeleteTaskComments, 'forceDelete',
            fn (TaskComment $entry): ?bool => $entry->forceDelete(), trashed: true);

        return response()->json(['message' => __('tasks.task_comment_force_deleted'), 'code' => 'task_comment_force_deleted']);
    }

    /**
     * @param  'update'|'delete'|'restore'|'forceDelete'  $ability
     * @param  Closure(TaskComment): (bool|null)  $operation
     */
    private function change(
        Request $request,
        string $task,
        string $comment,
        PermissionKey $permission,
        string $ability,
        Closure $operation,
        bool $trashed = false,
    ): TaskComment {
        $taskId = AuthorizationInput::identifier($task);
        $commentId = AuthorizationInput::identifier($comment);

        return $this->write->run($request, $permission, function (User $actor, AuditContext $context) use ($taskId, $commentId, $ability, $operation, $trashed): TaskComment {
            [$record, $case] = $this->scope->lockActive($taskId, $actor);
            $query = $record->comments();
            if ($trashed) {
                $query->onlyTrashed();
            }
            $entry = $query->lockForUpdate()->findOrFail($commentId);
            Gate::forUser($actor)->authorize($ability, [$entry, $record, $case]);
            $oldBody = $entry->body;
            if ($operation($entry) !== true) {
                throw new RuntimeException('Comment change did not complete.');
            }

            [$event, $before, $after] = match ($ability) {
                'update' => [AuditEvent::CommentUpdated, ['body' => $oldBody], ['body' => $entry->body]],
                'delete' => [AuditEvent::CommentDeleted, ['state' => 'active'], ['state' => 'trashed']],
                'restore' => [AuditEvent::CommentRestored, ['state' => 'trashed'], ['state' => 'active']],
                'forceDelete' => [AuditEvent::CommentForceDeleted, ['state' => 'trashed'], ['state' => 'deleted']],
            };
            $this->audit->record($context, $event, $entry, $before, $after, $case,
                ['task_id' => $record->id, 'author_id' => $entry->author_user_id]);

            return $entry;
        });
    }
}
