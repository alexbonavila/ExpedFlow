<?php

namespace App\Support;

use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class RoleManagement
{
    public function __construct(private readonly AdministrativeWrite $write, private readonly AuditRecorder $audit) {}

    /** @return Collection<int, Role> */
    public function all(): Collection
    {
        return AuthorizationCatalog::roles()->with('permissions')->orderBy('id')->get();
    }

    public function find(int $id): Role
    {
        return AuthorizationCatalog::roles()->with('permissions')->findOrFail($id);
    }

    /** @param list<string> $keys */
    public function create(User $actor, string $name, array $keys): Role
    {
        return $this->write->run($actor, PermissionKey::ManageRoles, function (User $actor, Role $administrator, AuditContext $context) use ($name, $keys): Role {
            $role = new Role(['name' => $name, 'guard_name' => AuthorizationCatalog::GUARD]);
            $this->save($role);
            $this->synchronize($role, $keys);
            $this->audit->record($context, AuditEvent::RoleCreated, $role, after: ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()]);

            return $role;
        });
    }

    public function rename(User $actor, int $id, string $name): Role
    {
        return $this->write->run($actor, PermissionKey::ManageRoles, function (User $actor, Role $administrator, AuditContext $context) use ($id, $name): Role {
            $role = $this->lock($id);
            $this->assertCustom($role);
            $before = $role->name;
            $role->name = $name;
            $this->save($role);
            $this->audit->record($context, AuditEvent::RoleUpdated, $role, ['name' => $before], ['name' => $role->name]);
            AuthorizationCatalog::flushAfterCommit();

            return $role->load('permissions');
        });
    }

    /** @param list<string> $keys */
    public function replacePermissions(User $actor, int $id, array $keys): Role
    {
        return $this->write->run($actor, PermissionKey::ManageRoles, function (User $actor, Role $administrator, AuditContext $context) use ($id, $keys): Role {
            $role = $this->lock($id);
            $before = $this->recordedPermissions($role);
            $this->synchronize($role, $keys);
            $this->audit->recordSet($context, AuditEvent::RolePermissionsChanged, $role, 'permissions', $before, $this->recordedPermissions($role));

            return $role;
        });
    }

    public function delete(User $actor, int $id): void
    {
        $this->write->run($actor, PermissionKey::ManageRoles, function (User $actor, Role $administrator, AuditContext $context) use ($id): void {
            $role = $this->lock($id);
            $this->assertCustom($role);
            if (DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
                throw new IdentityError(409, 'role_assigned');
            }

            $before = ['name' => $role->name, 'permissions' => $this->recordedPermissions($role)];
            if ($role->deleteQuietly()) {
                $this->audit->record($context, AuditEvent::RoleDeleted, $role, $before);
            }
            AuthorizationCatalog::flushAfterCommit();
        });
    }

    /** @return list<string> */
    private function recordedPermissions(Role $role): array
    {
        return array_values($role->loadMissing('permissions')->permissions->map(fn (Permission $permission): string => $permission->name)
            ->filter(fn (string $key): bool => PermissionKey::tryFrom($key) !== null)->all());
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

    private function lock(int $id): Role
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
