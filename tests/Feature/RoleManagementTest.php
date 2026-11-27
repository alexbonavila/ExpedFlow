<?php

use App\Models\User;
use App\Support\PermissionKey;
use Database\Seeders\AuthorizationSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\IdentityHttp;
use Tests\Support\RefreshGuardedMySql;

uses(IdentityHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

function roleAdmin(string $locale = 'en'): User
{
    return User::factory()->create(['locale' => $locale])->assignRole('admin');
}

/** @return array<string, string> */
function roleBearer(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('Authorization fixture', ['*'])->plainTextToken];
}

test('only an active administrator with a grant can read role and permission administration', function (): void {
    $ordinary = User::factory()->create()->assignRole('user')->givePermissionTo('roles.manage');
    $disabled = User::factory()->create(['disabled_at' => now()])->assignRole('admin');
    $admin = roleAdmin();
    $this->api('GET', '/api/v1/roles')->assertUnauthorized();
    $this->api('GET', '/api/v1/permissions', headers: ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    $this->api('GET', '/api/v1/roles', headers: roleBearer($ordinary))->assertForbidden();
    $this->api('GET', '/api/v1/roles', headers: roleBearer($disabled))->assertUnauthorized();
    $headers = roleBearer($admin);
    $this->api('GET', '/api/v1/roles', headers: $headers)->assertOk()
        ->assertJsonPath('data.0.name', 'admin')->assertJsonPath('data.1.name', 'user')
        ->assertHeader('Cache-Control', 'no-store, private');
    Role::findByName('admin', 'web')->revokePermissionTo('roles.manage');
    $this->api('GET', '/api/v1/roles', headers: $headers)->assertForbidden();
});

test('permission metadata is complete and localized with stable keys', function (string $locale, string $label): void {
    $response = $this->api('GET', '/api/v1/permissions', headers: roleBearer(roleAdmin($locale)))
        ->assertOk()->assertJsonCount(count(PermissionKey::cases()), 'data')
        ->assertJsonPath('data.0.key', 'users.manage')->assertJsonPath('data.0.group', 'users')
        ->assertJsonPath('data.0.label', $label)->assertJsonPath('data.0.restricted', true)
        ->assertJsonPath('data.3.restricted', false);
    expect($response->json('data.0.description'))->toContain($label);
    $response->assertJsonMissingPath('data.0.id');
})->with([['en', 'Manage users'], ['es', 'Gestionar usuarios'], ['ca', 'Gestionar usuaris']]);

test('administrators create rename replace and delete custom roles without exposing pivots', function (): void {
    $headers = roleBearer(roleAdmin());
    $response = $this->api('POST', '/api/v1/roles', [
        'name' => '  Case reviewer  ', 'permissions' => ['tasks.view', 'expedients.view'],
        'guard_name' => 'api', 'id' => 42, 'description' => 'ignored',
    ], $headers)->assertCreated();
    $id = $response->json('data.id');
    $response->assertExactJson(['data' => ['id' => $id, 'name' => 'Case reviewer', 'permissions' => ['expedients.view', 'tasks.view']]]);
    $this->assertDatabaseHas('roles', ['id' => $id, 'name' => 'Case reviewer', 'guard_name' => 'web']);
    $path = "/api/v1/roles/$id";
    $this->api('GET', $path, headers: $headers)->assertOk()->assertExactJson($response->json());
    $this->api('PATCH', $path, ['name' => 'Case coordinator', 'permissions' => ['*']], $headers)->assertOk()
        ->assertJsonPath('data.name', 'Case coordinator')->assertJsonPath('data.permissions', ['expedients.view', 'tasks.view']);
    $this->api('PUT', "$path/permissions", ['permissions' => []], $headers)->assertOk()->assertJsonPath('data.permissions', []);
    $this->assertDatabaseMissing('role_has_permissions', ['role_id' => $id]);
    $this->api('DELETE', $path, headers: $headers)->assertOk()->assertExactJson(['code' => 'role_deleted', 'message' => 'The role was deleted.']);
    $this->api('GET', $path, headers: $headers)->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->assertDatabaseMissing('roles', ['id' => $id]);
});

test('custom role name collisions follow MySQL case and accent rules', function (): void {
    $headers = roleBearer(roleAdmin('ca'));
    $this->api('POST', '/api/v1/roles', ['name' => 'Càtaleg', 'permissions' => []], $headers)->assertCreated();
    foreach ([' CATALEG ', ' ÁDMIN ', 'UsÉr'] as $name) {
        $this->api('POST', '/api/v1/roles', ['name' => $name, 'permissions' => []], $headers)
            ->assertConflict()->assertJsonPath('code', 'role_name_conflict')
            ->assertJsonPath('message', 'Aquest nom de rol ja es fa servir.');
    }
    $this->assertDatabaseCount('roles', 3);
});

test('role replacements reject restricted and malformed grants without partial changes', function (): void {
    $headers = roleBearer(roleAdmin());
    $role = Role::query()->create(['name' => 'Reviewers', 'guard_name' => 'web']);
    $role->givePermissionTo('tasks.view');
    $path = "/api/v1/roles/{$role->id}/permissions";
    Permission::query()->create(['name' => 'external.view', 'guard_name' => 'api']);

    foreach ([
        'missing' => [], 'null' => ['permissions' => null], 'scalar' => ['permissions' => 'tasks.view'],
        'map' => ['permissions' => ['forged' => 'tasks.view']],
        'empty object' => ['permissions' => new stdClass],
        'duplicate' => ['permissions' => ['tasks.view', 'tasks.view']],
        'wildcard' => ['permissions' => ['tasks.*']], 'foreign' => ['permissions' => ['external.view']],
        'nested' => ['permissions' => [['name' => 'tasks.view']]], 'restricted' => ['permissions' => ['expedients.force-delete']],
        'unknown' => ['permissions' => ['unregistered.view']],
    ] as $invalid) {
        $this->api('PUT', $path, $invalid, $headers)->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        expect($role->permissions()->pluck('name')->all())->toBe(['tasks.view']);
    }

    $admin = Role::findByName('admin', 'web');
    $this->api('PUT', "/api/v1/roles/{$admin->id}/permissions", ['permissions' => []], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('permissions');
    expect($admin->permissions()->count())->toBe(count(PermissionKey::cases()));
});

test('role deletion preserves disabled assignments and system roles', function (): void {
    $headers = roleBearer(roleAdmin());
    $custom = Role::query()->create(['name' => 'Assigned', 'guard_name' => 'web']);
    $disabled = User::factory()->create(['disabled_at' => now()])->assignRole($custom);
    $foreign = Role::query()->create(['name' => 'External', 'guard_name' => 'api']);

    $this->api('DELETE', "/api/v1/roles/{$custom->id}", headers: $headers)->assertConflict()->assertJsonPath('code', 'role_assigned');
    $this->api('GET', "/api/v1/roles/{$foreign->id}", headers: $headers)->assertNotFound();
    foreach (['admin', 'user'] as $name) {
        $role = Role::findByName($name, 'web');
        $this->api('DELETE', "/api/v1/roles/{$role->id}", headers: $headers)->assertConflict()->assertJsonPath('code', 'system_role');
        $this->api('PATCH', "/api/v1/roles/{$role->id}", ['name' => 'Renamed'], $headers)->assertConflict()->assertJsonPath('code', 'system_role');
    }
    expect($disabled->roles()->sole()->is($custom))->toBeTrue();
});
