<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

final class AccountLifecycle
{
    public function __construct(private readonly RevokeCredentials $credentials) {}

    public function disable(User $actor, User $target): User
    {
        if ($actor->is($target)) {
            throw new IdentityError(409, 'self_disable');
        }

        return DB::transaction(function () use ($actor, $target): User {
            $role = Role::query()->where('name', User::ADMIN_ROLE)->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
            $account = User::query()->lockForUpdate()->findOrFail($target->id);
            if ($account->disabled_at !== null) {
                return $account;
            }

            if ($account->hasRole($role) && User::role($role)->whereNull('disabled_at')->count() <= 1) {
                throw new IdentityError(409, 'last_active_admin');
            }

            if (User::query()->lockForUpdate()->findOrFail($actor->id)->disabled_at !== null) {
                throw new IdentityError(401, 'unauthenticated');
            }

            $account->forceFill(['disabled_at' => now()]);
            $this->credentials->revoke($account);

            return $account;
        }, 3);
    }

    public function enable(User $target): User
    {
        return DB::transaction(function () use ($target): User {
            $account = User::query()->lockForUpdate()->findOrFail($target->id);
            $account->forceFill(['disabled_at' => null])->save();

            return $account;
        }, 3);
    }
}
