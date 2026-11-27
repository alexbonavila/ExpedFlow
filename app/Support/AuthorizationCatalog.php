<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class AuthorizationCatalog
{
    public const string GUARD = 'web';

    public const string ORDINARY_ROLE = 'user';

    public const string BOOTSTRAP_PENDING = 'authorization_admin_pending';

    public function install(): void
    {
        DB::transaction(function (): void {
            [$admin, $created] = $this->ensureAdministratorRole();
            if ($created) {
                DB::table('seeded_catalogs')->insert(['catalog' => self::BOOTSTRAP_PENDING]);
            }

            $ordinary = $this->ensureRole(self::ORDINARY_ROLE);
            $first = DB::table('seeded_catalogs')->insertOrIgnore(['catalog' => 'authorization_v1']) === 1;
            $timestamp = now();
            Permission::query()->insertOrIgnore(array_map(
                fn (PermissionKey $key): array => [
                    'name' => $key->value,
                    'guard_name' => self::GUARD,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                PermissionKey::cases(),
            ));

            $ordinaryGrants = [];
            $adminGrants = [];
            foreach (self::permissions()->get() as $permission) {
                $key = PermissionKey::from($permission->name);
                if ($key->restricted() || $first) {
                    $adminGrants[] = $permission->id;
                }
                if (! $key->restricted()) {
                    $ordinaryGrants[] = $permission->id;
                }
            }
            $admin->permissions()->syncWithoutDetaching($adminGrants);

            if ($first) {
                $ordinary->permissions()->syncWithoutDetaching($ordinaryGrants);
                User::query()->whereDoesntHave('roles', fn (Builder $roles): Builder => $roles->whereKey($admin->id))
                    ->eachById(fn (User $user): array => $user->roles()->syncWithoutDetaching([$ordinary->id]));
            }

            self::flushAfterCommit();
        }, 3);
    }

    /** @return array{Role, bool} */
    public function ensureAdministratorRole(): array
    {
        $created = $this->insertRole(User::ADMIN_ROLE);

        return [self::lockAdministratorRole(), $created];
    }

    public static function lockAdministratorRole(): Role
    {
        $role = self::roles()->where('name', User::ADMIN_ROLE)->lockForUpdate()->firstOrFail();

        if ($role->name !== User::ADMIN_ROLE) {
            throw new LogicException('Reserved administrator role name requires owner review.');
        }

        return $role;
    }

    /** @return Builder<Role> */
    public static function roles(): Builder
    {
        return Role::query()->where('guard_name', self::GUARD);
    }

    /** @return Builder<Permission> */
    public static function permissions(): Builder
    {
        return Permission::query()->where('guard_name', self::GUARD)
            ->whereIn('name', array_column(PermissionKey::cases(), 'value'));
    }

    public static function flushAfterCommit(): void
    {
        DB::afterCommit(static function (): void {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }

    private function ensureRole(string $name): Role
    {
        $this->insertRole($name);
        $role = self::roles()->where('name', $name)->lockForUpdate()->firstOrFail();
        if ($role->name !== $name) {
            throw new LogicException('Reserved ordinary role name requires owner review.');
        }

        return $role;
    }

    private function insertRole(string $name): bool
    {
        return Role::query()->insertOrIgnore([
            'name' => $name,
            'guard_name' => self::GUARD,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
