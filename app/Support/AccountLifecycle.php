<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Models\Role;

final class AccountLifecycle
{
    public function __construct(
        private readonly RevokeCredentials $credentials,
        private readonly AdministrativeWrite $write,
    ) {}

    public function disable(User $actor, User $target): User
    {
        return $this->write->run($actor, PermissionKey::ManageUsers, function (User $account, Role $role) use ($actor): User {
            if ($actor->is($account)) {
                throw new IdentityError(409, 'self_disable');
            }
            if ($account->disabled_at !== null) {
                return $account;
            }

            AdministrativeWrite::assertRemovableAdministrator($role, $account);
            $account->forceFill(['disabled_at' => now()]);
            $this->credentials->revoke($account);

            return $account;
        }, $target);
    }

    public function enable(User $actor, User $target): User
    {
        return $this->write->run($actor, PermissionKey::ManageUsers, function (User $account): User {
            $account->forceFill(['disabled_at' => null])->save();

            return $account;
        }, $target);
    }
}
