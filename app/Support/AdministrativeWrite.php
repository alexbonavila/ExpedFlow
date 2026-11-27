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
     * @param  Closure(Role): TResult  $operation
     * @return TResult
     */
    public function run(User $actor, PermissionKey $permission, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $permission, $operation): mixed {
            $adminRole = AuthorizationCatalog::lockAdministratorRole();
            $account = User::query()->lockForUpdate()->findOrFail($actor->id);

            if ($account->disabled_at !== null) {
                throw new IdentityError(401, 'unauthenticated');
            }
            Gate::forUser($account)->allowIf(fn (User $user): bool => $this->permissions->allows($user, $permission));

            return $operation($adminRole);
        }, 3);
    }
}
