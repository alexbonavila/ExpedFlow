<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class RoleAssignments
{
    public function __construct(private readonly AdministrativeWrite $write) {}

    /** @param list<int> $ids */
    public function replace(User $actor, User $target, array $ids): User
    {
        return $this->write->run($actor, PermissionKey::AssignRoles, function (Role $admin, User $account) use ($actor, $ids): User {
            $roles = AuthorizationCatalog::roles()->whereIntegerInRaw('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($roles->count() !== count($ids)) {
                throw ValidationException::withMessages(['role_ids' => __('identity.unknown_roles')]);
            }

            if (! $roles->contains($admin)) {
                if ($actor->is($account)) {
                    throw new IdentityError(409, 'self_demotion');
                }
                self::assertRemovableAdministrator($admin, $account);
            }

            $otherGuards = $account->roles()->where('roles.guard_name', '!=', AuthorizationCatalog::GUARD)->pluck('roles.id')->all();
            $account->roles()->sync([...$roles->modelKeys(), ...$otherGuards]);

            return $account;
        }, $target);
    }

    public static function assertRemovableAdministrator(Role $admin, User $account): void
    {
        if ($account->disabled_at === null
            && $account->roles()->whereKey($admin->id)->exists()
            && ! User::role($admin)->whereNull('disabled_at')->whereKeyNot($account->id)->exists()) {
            throw new IdentityError(409, 'last_active_admin');
        }
    }
}
