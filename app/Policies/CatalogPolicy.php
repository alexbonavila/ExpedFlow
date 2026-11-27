<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\ExpedientType;
use App\Models\Status;
use App\Models\Tag;
use App\Models\User;
use App\Support\EffectivePermissions;
use App\Support\PermissionKey;

final class CatalogPolicy
{
    public function __construct(private readonly EffectivePermissions $permissions) {}

    public function delete(User $actor, Client|ExpedientType|Status|Tag $record): bool
    {
        return match (true) {
            $record instanceof Client => $this->permissions->allows($actor, PermissionKey::ManageClients)
                && $record->requestedExpedients()->withTrashed()->doesntExist()
                && $record->representedExpedients()->withTrashed()->doesntExist(),
            $record instanceof ExpedientType => $this->permissions->allows($actor, PermissionKey::ManageExpedientTypes)
                && $record->expedients()->withTrashed()->doesntExist()
                && $record->templates()->doesntExist(),
            $record instanceof Status => $this->permissions->allows($actor, PermissionKey::ManageStatuses)
                && $record->expedients()->withTrashed()->doesntExist()
                && $record->tasks()->withTrashed()->doesntExist(),
            default => $this->permissions->allows($actor, PermissionKey::ManageTags),
        };
    }
}
