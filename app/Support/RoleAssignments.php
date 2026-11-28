<?php

namespace App\Support;

use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class RoleAssignments
{
    public function __construct(private readonly AdministrativeWrite $write, private readonly AuditRecorder $audit) {}

    public function assignDefault(User $account): void
    {
        $role = AuthorizationCatalog::roles()->where('name', AuthorizationCatalog::ORDINARY_ROLE)->firstOrFail();
        $account->assignRole($role);
    }

    /** @param list<int> $ids */
    public function replace(User $actor, User $target, array $ids): User
    {
        return $this->write->run($actor, PermissionKey::AssignRoles, function (User $account, Role $admin, AuditContext $context, User $administrator) use ($ids): User {
            $roles = AuthorizationCatalog::roles()->whereIntegerInRaw('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($roles->count() !== count($ids)) {
                throw ValidationException::withMessages(['role_ids' => __('identity.unknown_roles')]);
            }

            if (! $roles->contains($admin)) {
                if ($administrator->is($account)) {
                    throw new IdentityError(409, 'self_demotion');
                }
                AdministrativeWrite::assertRemovableAdministrator($admin, $account);
            }

            $before = $account->roles()->whereRaw('CAST(roles.guard_name AS BINARY) = ?', [AuthorizationCatalog::GUARD])
                ->toBase()->pluck('roles.id')->map(fn (mixed $id): int => (int) $id)->all();
            $otherGuards = $account->roles()->whereRaw('CAST(roles.guard_name AS BINARY) <> ?', [AuthorizationCatalog::GUARD])->pluck('roles.id')->all();
            $account->roles()->sync([...$roles->modelKeys(), ...$otherGuards]);
            $this->audit->recordSet($context, AuditEvent::UserRolesChanged, $account, 'roles', $before, $roles->map(fn (Role $role): int => (int) $role->id)->all());

            return $account;
        }, $target);
    }
}
