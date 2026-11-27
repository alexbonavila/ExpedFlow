<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class RoleAssignments
{
    public function __construct(private readonly AdministrativeWrite $write) {}

    public function assignDefault(User $account): void
    {
        $role = AuthorizationCatalog::roles()->where('name', AuthorizationCatalog::ORDINARY_ROLE)->firstOrFail();
        $account->assignRole($role);
    }

    /** @param list<int> $ids */
    public function replace(User $actor, User $target, array $ids): User
    {
        return $this->write->run($actor, PermissionKey::AssignRoles, function (User $account, Role $admin) use ($actor, $ids): User {
            $roles = AuthorizationCatalog::roles()->whereIntegerInRaw('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($roles->count() !== count($ids)) {
                throw ValidationException::withMessages(['role_ids' => __('identity.unknown_roles')]);
            }

            if (! $roles->contains($admin)) {
                if ($actor->is($account)) {
                    throw new IdentityError(409, 'self_demotion');
                }
                AdministrativeWrite::assertRemovableAdministrator($admin, $account);
            }

            $otherGuards = $account->roles()->whereRaw('CAST(roles.guard_name AS BINARY) <> ?', [AuthorizationCatalog::GUARD])->pluck('roles.id')->all();
            $account->roles()->sync([...$roles->modelKeys(), ...$otherGuards]);

            return $account;
        }, $target);
    }
}
