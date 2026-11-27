<?php

use App\Support\PermissionKey;

it('keeps administrative and permanent deletion permissions out of the delegable catalog', function (): void {
    $restricted = array_filter(PermissionKey::cases(), fn (PermissionKey $key): bool => $key->restricted());

    expect(array_values(array_column($restricted, 'value')))->toBe([
        'users.manage', 'roles.manage', 'roles.assign', 'statuses.manage',
        'expedients.force-delete', 'tasks.force-delete', 'task-comments.force-delete',
    ]);
});
