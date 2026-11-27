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
        return ! $comment->trashed() && $comment->task_id === $task->id && $this->tasks->view($actor, $task, $expedient);
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
}
