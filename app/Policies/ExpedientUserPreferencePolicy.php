<?php

namespace App\Policies;

use App\Models\Expedient;
use App\Models\ExpedientUserPreference;
use App\Models\User;
use App\Support\EffectivePermissions;
use App\Support\PermissionKey;

final class ExpedientUserPreferencePolicy
{
    public function __construct(
        private readonly EffectivePermissions $permissions,
        private readonly ExpedientPolicy $expedients,
    ) {}

    public function manage(User $actor, Expedient $expedient, User $owner, ?ExpedientUserPreference $preference = null): bool
    {
        return $actor->is($owner)
            && ($preference === null || ($preference->user_id === $actor->id && $preference->expedient_id === $expedient->id))
            && $this->expedients->view($actor, $expedient)
            && $this->permissions->allows($actor, PermissionKey::ManagePersonalKanban);
    }
}
