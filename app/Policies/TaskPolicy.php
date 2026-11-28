<?php

namespace App\Policies;

use App\Models\Expedient;
use App\Models\Task;
use App\Models\User;
use App\Support\EffectivePermissions;
use App\Support\PermissionKey;

final class TaskPolicy
{
    public function __construct(
        private readonly EffectivePermissions $permissions,
        private readonly ExpedientPolicy $expedients,
    ) {}

    public function viewAny(User $actor, Expedient $expedient): bool
    {
        return $this->expedients->view($actor, $expedient)
            && $this->permissions->allows($actor, PermissionKey::ViewTasks);
    }

    public function view(User $actor, Task $task, Expedient $expedient): bool
    {
        return ! $task->trashed() && $this->inScope($actor, $task, $expedient);
    }

    public function create(User $actor, Expedient $expedient): bool
    {
        return $this->expedients->view($actor, $expedient)
            && $this->permissions->allows($actor, PermissionKey::CreateTasks);
    }

    public function update(User $actor, Task $task, Expedient $expedient): bool
    {
        return $this->view($actor, $task, $expedient) && $this->permissions->allows($actor, PermissionKey::UpdateTasks);
    }

    public function assign(User $actor, Task $task, Expedient $expedient): bool
    {
        return $this->view($actor, $task, $expedient) && $this->permissions->allows($actor, PermissionKey::AssignTasks);
    }

    public function reorder(User $actor, Task $task, Expedient $expedient): bool
    {
        return $this->view($actor, $task, $expedient) && $this->permissions->allows($actor, PermissionKey::ReorderTasks);
    }

    public function viewCalendar(User $actor): bool
    {
        $grants = $this->permissions->for($actor);

        return in_array(PermissionKey::ViewCalendar->value, $grants, true)
            && in_array(PermissionKey::ViewTasks->value, $grants, true)
            && in_array(PermissionKey::ViewExpedients->value, $grants, true);
    }

    public function calendar(User $actor, Task $task, Expedient $expedient, User $owner): bool
    {
        return $actor->is($owner)
            && $task->scheduled_date !== null
            && $this->view($actor, $task, $expedient)
            && $this->viewCalendar($actor)
            && $task->assignees()->whereKey($actor->id)->exists();
    }

    public function delete(User $actor, Task $task, Expedient $expedient): bool
    {
        return $this->view($actor, $task, $expedient) && $this->permissions->allows($actor, PermissionKey::DeleteTasks);
    }

    public function viewAnyTrash(User $actor, Expedient $expedient): bool
    {
        return $this->viewAny($actor, $expedient)
            && ($this->permissions->allows($actor, PermissionKey::RestoreTasks)
                || $this->permissions->allows($actor, PermissionKey::ForceDeleteTasks));
    }

    public function viewTrash(User $actor, Task $task, Expedient $expedient): bool
    {
        return $task->trashed() && $task->expedient_id === $expedient->id && $this->viewAnyTrash($actor, $expedient);
    }

    public function restore(User $actor, Task $task, Expedient $expedient): bool
    {
        return $task->trashed() && ! $task->deleted_by_expedient && $this->inScope($actor, $task, $expedient)
            && $this->permissions->allows($actor, PermissionKey::RestoreTasks);
    }

    public function forceDelete(User $actor, Task $task, Expedient $expedient): bool
    {
        return $task->trashed() && $this->inScope($actor, $task, $expedient)
            && $this->permissions->allows($actor, PermissionKey::ForceDeleteTasks);
    }

    private function inScope(User $actor, Task $task, Expedient $expedient): bool
    {
        return $task->expedient_id === $expedient->id && $this->viewAny($actor, $expedient);
    }
}
