<?php

use App\Models\User;
use App\Support\EffectivePermissions;
use Database\Seeders\AuthorizationSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\AuthorizationHttp;
use Tests\Support\RefreshGuardedMySql;

uses(AuthorizationHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('role replacement applies to retained models and existing browser and bearer credentials', function (): void {
    $admin = $this->authorizationAdmin();
    $headers = $this->authorizationBearer($admin);
    $account = User::factory()->create()->givePermissionTo('tasks.view');
    $role = Role::query()->create(['name' => 'Coordinator', 'guard_name' => 'web']);
    $role->givePermissionTo('expedients.view', 'tasks.view');
    $retained = $account->load(['roles.permissions', 'permissions']);
    $bearer = $this->authorizationBearer($account);
    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $account->email, 'password' => 'password'])
        ->assertOk()->assertJsonPath('data.permissions', []);
    $this->api('GET', '/api/v1/me', headers: $bearer)->assertOk()->assertJsonPath('data.permissions', []);
    $authorization = "/api/v1/users/{$account->id}/authorization";
    $roles = "/api/v1/users/{$account->id}/roles";

    $this->api('PUT', $roles, ['role_ids' => [$role->id], 'permissions' => ['roles.manage']], $headers)
        ->assertOk()->assertExactJson(['data' => [
            'roles' => [['id' => $role->id, 'name' => 'Coordinator']],
            'permissions' => ['expedients.view', 'tasks.view'],
        ]]);
    $this->api('GET', $authorization, headers: $headers)->assertOk()
        ->assertJsonPath('data.permissions', ['expedients.view', 'tasks.view'])
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->api('GET', '/api/v1/me', headers: $bearer)->assertOk()->assertJsonPath('data.permissions', ['expedients.view', 'tasks.view']);
    $this->spa('GET', '/me')->assertOk()->assertJsonPath('data.permissions', ['expedients.view', 'tasks.view']);
    expect(app(EffectivePermissions::class)->for($retained))->toBe(['expedients.view', 'tasks.view']);

    $this->api('PUT', "/api/v1/roles/{$role->id}/permissions", ['permissions' => []], $headers)
        ->assertOk()->assertJsonPath('data.permissions', []);
    $this->spa('GET', '/me')->assertOk()->assertJsonPath('data.permissions', ['tasks.view']);
    $this->api('GET', '/api/v1/me', headers: $bearer)->assertOk()->assertJsonPath('data.permissions', ['tasks.view']);

    $this->api('PUT', $roles, ['role_ids' => []], $headers)->assertOk()->assertJsonPath('data.roles', []);
    $this->spa('GET', '/me')->assertOk()->assertJsonPath('data.permissions', []);
    expect($account->permissions()->pluck('name')->all())->toBe(['tasks.view']);
});

test('membership updates reject coercion tampering and unavailable IDs atomically', function (): void {
    $admin = $this->authorizationAdmin('es');
    $headers = $this->authorizationBearer($admin);
    $account = User::factory()->create()->assignRole('user');
    $web = Role::findByName('user', 'web');
    $foreign = Role::query()->create(['name' => 'External', 'guard_name' => 'api']);
    $path = "/api/v1/users/{$account->id}/roles";
    foreach ([
        [], ['role_ids' => null], ['role_ids' => new stdClass], ['role_ids' => [$web->id, $web->id]],
        ['role_ids' => [(string) $web->id]], ['role_ids' => [-5]], ['role_ids' => ['admin']],
        ['role_ids' => [$web->id, 999999]], ['role_ids' => [$foreign->id]],
        ['role_ids' => [['id' => $web->id]]], ['role_ids' => [0]], ['role_ids' => [true]],
    ] as $invalid) {
        $this->api('PUT', $path, $invalid, $headers)->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        expect($account->roles()->sole()->is($web))->toBeTrue();
    }

    $this->api('GET', '/api/v1/users/999999/authorization', headers: $headers)->assertNotFound();
    $this->api('PUT', '/api/v1/users/999999/roles', ['role_ids' => []], $headers)->assertNotFound();
    $this->api('PUT', $path, ['role_ids' => [$foreign->id]], $headers)
        ->assertUnprocessable()->assertJsonPath('errors.role_ids.0', 'Uno o más roles no están disponibles.');
});

test('only a current administrator with assignment authority can promote or demote another account', function (): void {
    $admin = $this->authorizationAdmin();
    $adminRole = Role::findByName('admin', 'web');
    $account = User::factory()->create()->assignRole('user')->givePermissionTo(['roles.assign', 'users.manage']);
    $headers = $this->authorizationBearer($admin);
    $ordinary = $this->authorizationBearer($account);
    $path = "/api/v1/users/{$account->id}/roles";
    $this->api('PUT', $path, ['role_ids' => [$adminRole->id]], $ordinary)->assertForbidden();
    $this->api('GET', "/api/v1/users/{$account->id}/authorization", headers: $ordinary)->assertForbidden();
    $this->api('PUT', "/api/v1/users/{$admin->id}/roles", ['role_ids' => []], $headers)
        ->assertConflict()->assertJsonPath('code', 'self_demotion');

    $this->api('PUT', $path, ['role_ids' => [$adminRole->id]], $headers)->assertOk()->assertJsonPath('data.roles.0.name', 'admin');
    $this->api('GET', "/api/v1/users/{$admin->id}/authorization", headers: $ordinary)->assertOk();
    $this->api('PUT', $path, ['role_ids' => []], $headers)->assertOk()->assertJsonPath('data.roles', []);
    $this->api('GET', "/api/v1/users/{$admin->id}/authorization", headers: $ordinary)->assertForbidden();
    expect($account->permissions()->orderBy('name')->pluck('name')->all())->toBe(['roles.assign', 'users.manage']);

    $adminRole->revokePermissionTo('roles.assign');
    $this->api('PUT', $path, ['role_ids' => [$adminRole->id]], $headers)->assertForbidden();
});

test('a browser requires real CSRF to replace memberships and can inspect disabled accounts', function (): void {
    $admin = $this->authorizationAdmin();
    $disabled = User::factory()->create(['disabled_at' => now()])->assignRole('user');
    $role = Role::findByName('admin', 'web');
    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
    $path = "/users/{$disabled->id}";
    $this->api('PUT', "/api/v1$path/roles", ['role_ids' => [$role->id]], browser: true)
        ->assertStatus(419)->assertJsonPath('code', 'csrf_mismatch');
    $this->spa('GET', "$path/authorization")->assertOk()->assertJsonPath('data.roles.0.name', 'user')
        ->assertJsonPath('data.permissions', []);
    $this->spa('PUT', "$path/roles", ['role_ids' => [$role->id]])->assertOk()
        ->assertJsonPath('data.roles.0.name', 'admin')->assertJsonPath('data.permissions', []);
    expect($disabled->fresh()->disabled_at)->not->toBeNull();
});

test('a guard variant never confers authority or enters the web assignment surface', function (): void {
    $actor = $this->authorizationAdmin();
    $account = User::factory()->create()->givePermissionTo('tasks.view');
    $foreign = Role::query()->create(['name' => 'Foreign guard role', 'guard_name' => 'WEB']);
    $grant = Permission::findByName('expedients.view', 'web');
    $foreign->permissions()->attach($grant->id);
    $account->roles()->attach($foreign->id);
    $headers = $this->authorizationBearer($actor);

    $this->api('GET', '/api/v1/me', headers: $this->authorizationBearer($account))->assertOk()
        ->assertJsonPath('data.permissions', []);
    $this->api('GET', "/api/v1/roles/{$foreign->id}", headers: $headers)->assertNotFound();
    $this->api('PUT', "/api/v1/users/{$account->id}/roles", ['role_ids' => [$foreign->id]], $headers)->assertUnprocessable();
    $this->api('PUT', "/api/v1/users/{$account->id}/roles", ['role_ids' => []], $headers)->assertOk()
        ->assertJsonPath('data.roles', []);
    expect($account->roles()->sole()->is($foreign))->toBeTrue();
});
