<?php

namespace App\Policies;

use App\Models\Expedient;
use App\Models\Task;
use App\Models\User;
use App\Support\EffectivePermissions;
use App\Support\PermissionKey;

final class ExpedientPolicy
{
    public function __construct(private readonly EffectivePermissions $permissions) {}

    public function viewAny(User $actor): bool
    {
        return $this->permissions->allows($actor, PermissionKey::ViewExpedients);
    }

    public function view(User $actor, Expedient $expedient): bool
    {
        return ! $expedient->trashed() && $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->permissions->allows($actor, PermissionKey::CreateExpedients);
    }

    public function update(User $actor, Expedient $expedient): bool
    {
        return ! $expedient->trashed() && $this->permissions->allows($actor, PermissionKey::UpdateExpedients);
    }

    public function associateTags(User $actor, Expedient $expedient): bool
    {
        return $this->view($actor, $expedient)
            && $this->permissions->allows($actor, PermissionKey::AssociateTags);
    }

    public function upload(User $actor, Expedient $expedient): bool
    {
        return $this->view($actor, $expedient)
            && $this->permissions->allows($actor, PermissionKey::UploadCaseFiles);
    }

    public function delete(User $actor, Expedient $expedient): bool
    {
        return ! $expedient->trashed() && $this->permissions->allows($actor, PermissionKey::DeleteExpedients);
    }

    public function viewAnyTrash(User $actor): bool
    {
        return $this->permissions->allows($actor, PermissionKey::RestoreExpedients)
            || $this->permissions->allows($actor, PermissionKey::ForceDeleteExpedients);
    }

    public function viewTrash(User $actor, Expedient $expedient): bool
    {
        return $expedient->trashed() && $this->viewAnyTrash($actor);
    }

    public function restore(User $actor, Expedient $expedient): bool
    {
        return $expedient->trashed() && $this->permissions->allows($actor, PermissionKey::RestoreExpedients);
    }

    public function restoreCascadeTask(User $actor, Expedient $expedient, Task $task): bool
    {
        return $task->expedient_id === $expedient->id && $task->trashed()
            && $task->deleted_by_expedient && $this->restore($actor, $expedient);
    }

    public function forceDelete(User $actor, Expedient $expedient): bool
    {
        return $expedient->trashed() && $this->permissions->allows($actor, PermissionKey::ForceDeleteExpedients);
    }
}
