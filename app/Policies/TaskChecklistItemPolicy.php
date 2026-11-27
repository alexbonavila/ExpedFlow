<?php

namespace App\Policies;

use App\Models\Expedient;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Support\EffectivePermissions;
use App\Support\PermissionKey;

final class TaskChecklistItemPolicy
{
    public function __construct(
        private readonly EffectivePermissions $permissions,
        private readonly TaskPolicy $tasks,
    ) {}

    public function manage(User $actor, Task $task, Expedient $expedient, ?TaskChecklistItem $item = null): bool
    {
        return ($item === null || $item->task_id === $task->id)
            && $this->tasks->view($actor, $task, $expedient)
            && $this->permissions->allows($actor, PermissionKey::ManageTaskChecklist);
    }
}
