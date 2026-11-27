<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;

final class EffectivePermissions
{
    public function allows(User $user, PermissionKey $permission): bool
    {
        return in_array($permission->value, $this->for($user), true);
    }

    /** @return list<string> */
    public function for(User $user): array
    {
        $roles = $user->roles()->getQuery()->where('roles.guard_name', AuthorizationCatalog::GUARD)->select('roles.id');
        $admin = (clone $roles)->whereRaw('CAST(roles.name AS BINARY) = ?', [User::ADMIN_ROLE]);
        $direct = $user->permissions()->getQuery()->select('permissions.id');
        $active = User::query()->whereKey($user->id)->whereNull('disabled_at');
        $restricted = array_filter(PermissionKey::cases(), fn (PermissionKey $key): bool => $key->restricted());

        return array_values(AuthorizationCatalog::permissions()
            ->whereExists($active)
            ->where(fn (Builder $grants): Builder => $grants->whereIn('permissions.id', $direct)
                ->orWhereHas('roles', fn (Builder $assigned): Builder => $assigned->whereIn('roles.id', $roles)))
            ->where(fn (Builder $allowed): Builder => $allowed->whereNotIn('name', array_column($restricted, 'value'))
                ->orWhereExists($admin))
            ->orderBy('name')
            ->get(['name'])
            ->map(fn (Permission $permission): string => $permission->name)
            ->filter(fn (string $name): bool => PermissionKey::tryFrom($name) !== null)
            ->all());
    }
}
