<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

final class AdministrativeWrite
{
    public function __construct(private readonly EffectivePermissions $permissions) {}

    /**
     * @template TResult
     *
     * @param  Closure(Role, User): TResult  $operation
     * @return TResult
     */
    public function run(User $actor, PermissionKey $permission, Closure $operation, ?User $target = null): mixed
    {
        return DB::transaction(function () use ($actor, $permission, $operation, $target): mixed {
            $adminRole = AuthorizationCatalog::lockAdministratorRole();
            $targetId = $target->id ?? $actor->id;
            $accounts = User::query()->whereKey([$actor->id, $targetId])->orderBy('id')->lockForUpdate()->get();
            $account = $accounts->findOrFail($actor->id);

            if ($account->disabled_at !== null || $account->auth_version !== $actor->auth_version) {
                throw new IdentityError(401, 'unauthenticated');
            }
            Gate::forUser($account)->allowIf(fn (User $user): bool => $this->permissions->allows($user, $permission));

            return $operation($adminRole, $accounts->findOrFail($targetId));
        }, 3);
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
