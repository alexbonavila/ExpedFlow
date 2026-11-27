<?php

namespace App\Policies;

use App\Models\Expedient;
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
}
