<?php

namespace App\Support;

use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use Spatie\Permission\Models\Role;

final class AccountLifecycle
{
    public function __construct(
        private readonly RevokeCredentials $credentials,
        private readonly AdministrativeWrite $write,
        private readonly AuditRecorder $audit,
    ) {}

    public function disable(User $actor, User $target): User
    {
        return $this->write->run($actor, PermissionKey::ManageUsers, function (User $account, Role $role, AuditContext $context, User $administrator): User {
            if ($administrator->is($account)) {
                throw new IdentityError(409, 'self_disable');
            }
            if ($account->disabled_at !== null) {
                return $account;
            }

            AdministrativeWrite::assertRemovableAdministrator($role, $account);
            $account->forceFill(['disabled_at' => now()]);
            $this->credentials->revoke($account);
            $this->audit->record($context, AuditEvent::UserDisabled, $account, ['enabled' => true], ['enabled' => false], metadata: ['credentials_revoked' => true]);

            return $account;
        }, $target);
    }

    public function enable(User $actor, User $target): User
    {
        return $this->write->run($actor, PermissionKey::ManageUsers, function (User $account, Role $role, AuditContext $context): User {
            $enabled = $account->disabled_at === null;
            $account->forceFill(['disabled_at' => null])->save();
            $this->audit->record($context, AuditEvent::UserEnabled, $account, ['enabled' => $enabled], ['enabled' => true]);

            return $account;
        }, $target);
    }
}
