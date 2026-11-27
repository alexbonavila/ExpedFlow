<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class RoleManagement
{
    public function __construct(private readonly AdministrativeWrite $write) {}

    /** @return Collection<int, Role> */
    public function all(): Collection
    {
        return AuthorizationCatalog::roles()->with('permissions')->orderBy('id')->get();
    }

    public function find(string $id): Role
    {
        return AuthorizationCatalog::roles()->with('permissions')->findOrFail($id);
    }

    /** @param list<string> $keys */
    public function create(User $actor, string $name, array $keys): Role
    {
        return $this->write->run($actor, PermissionKey::ManageRoles, function () use ($name, $keys): Role {
            $role = new Role(['name' => $name, 'guard_name' => AuthorizationCatalog::GUARD]);
            $this->save($role);
            $this->synchronize($role, $keys);

            return $role;
        });
    }

    public function rename(User $actor, string $id, string $name): Role
    {
        return $this->write->run($actor, PermissionKey::ManageRoles, function () use ($id, $name): Role {
            $role = $this->lock($id);
            $this->assertCustom($role);
            $role->name = $name;
            $this->save($role);
            AuthorizationCatalog::flushAfterCommit();

            return $role->load('permissions');
        });
    }

    /** @param list<string> $keys */
    public function replacePermissions(User $actor, string $id, array $keys): Role
    {
        return $this->write->run($actor, PermissionKey::ManageRoles, function () use ($id, $keys): Role {
            $role = $this->lock($id);
            $this->synchronize($role, $keys);

            return $role;
        });
    }

    public function delete(User $actor, string $id): void
    {
        $this->write->run($actor, PermissionKey::ManageRoles, function () use ($id): void {
            $role = $this->lock($id);
            $this->assertCustom($role);
            if (DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
                throw new IdentityError(409, 'role_assigned');
            }

            $role->deleteQuietly();
            AuthorizationCatalog::flushAfterCommit();
        });
    }

    /** @param list<string> $keys */
    private function synchronize(Role $role, array $keys): void
    {
        foreach (PermissionKey::cases() as $permission) {
            if ($permission->restricted() && in_array($permission->value, $keys, true) !== ($role->name === User::ADMIN_ROLE)) {
                throw ValidationException::withMessages(['permissions' => __('identity.restricted_permissions')]);
            }
        }

        $ids = AuthorizationCatalog::permissions()->whereIn('name', $keys)->get(['id', 'name'])
            ->filter(fn (Permission $permission): bool => in_array($permission->name, $keys, true))->modelKeys();
        if (count($ids) !== count($keys)) {
            throw ValidationException::withMessages(['permissions' => __('identity.unknown_permissions')]);
        }

        $role->permissions()->sync($ids);
        AuthorizationCatalog::flushAfterCommit();
        $role->load('permissions');
    }

    private function save(Role $role): void
    {
        try {
            $role->saveQuietly();
        } catch (UniqueConstraintViolationException) {
            throw new IdentityError(409, 'role_name_conflict');
        }
    }

    private function lock(string $id): Role
    {
        return AuthorizationCatalog::roles()->lockForUpdate()->findOrFail($id);
    }

    private function assertCustom(Role $role): void
    {
        if (in_array($role->name, [User::ADMIN_ROLE, AuthorizationCatalog::ORDINARY_ROLE], true)) {
            throw new IdentityError(409, 'system_role');
        }
    }
}
