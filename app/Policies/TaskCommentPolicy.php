<?php

namespace App\Policies;

use App\Models\Expedient;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\EffectivePermissions;
use App\Support\PermissionKey;

final class TaskCommentPolicy
{
    public function __construct(
        private readonly EffectivePermissions $permissions,
        private readonly TaskPolicy $tasks,
    ) {}

    public function view(User $actor, TaskComment $comment, Task $task, Expedient $expedient): bool
    {
        return ! $comment->trashed() && $this->inScope($actor, $comment, $task, $expedient);
    }

    public function create(User $actor, Task $task, Expedient $expedient): bool
    {
        return $this->tasks->view($actor, $task, $expedient)
            && $this->permissions->allows($actor, PermissionKey::CreateTaskComments);
    }

    public function update(User $actor, TaskComment $comment, Task $task, Expedient $expedient): bool
    {
        return $this->view($actor, $comment, $task, $expedient)
            && $this->permissions->allows($actor, PermissionKey::UpdateTaskComments);
    }

    public function delete(User $actor, TaskComment $comment, Task $task, Expedient $expedient): bool
    {
        return $this->view($actor, $comment, $task, $expedient)
            && $this->permissions->allows($actor, PermissionKey::DeleteTaskComments);
    }

    public function viewAnyTrash(User $actor, Task $task, Expedient $expedient): bool
    {
        return $this->tasks->view($actor, $task, $expedient)
            && ($this->permissions->allows($actor, PermissionKey::RestoreTaskComments)
                || $this->permissions->allows($actor, PermissionKey::ForceDeleteTaskComments));
    }

    public function viewTrash(User $actor, TaskComment $comment, Task $task, Expedient $expedient): bool
    {
        return $comment->trashed() && $comment->task_id === $task->id && $this->viewAnyTrash($actor, $task, $expedient);
    }

    public function restore(User $actor, TaskComment $comment, Task $task, Expedient $expedient): bool
    {
        return $comment->trashed() && $this->inScope($actor, $comment, $task, $expedient)
            && $this->permissions->allows($actor, PermissionKey::RestoreTaskComments);
    }

    public function forceDelete(User $actor, TaskComment $comment, Task $task, Expedient $expedient): bool
    {
        return $comment->trashed() && $this->inScope($actor, $comment, $task, $expedient)
            && $this->permissions->allows($actor, PermissionKey::ForceDeleteTaskComments);
    }

    private function inScope(User $actor, TaskComment $comment, Task $task, Expedient $expedient): bool
    {
        return $comment->task_id === $task->id && $this->tasks->view($actor, $task, $expedient);
    }
}
