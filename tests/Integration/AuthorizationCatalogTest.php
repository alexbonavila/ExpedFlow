<?php

use App\Models\Task;
use App\Models\User;
use App\Support\PermissionKey;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\RefreshGuardedMySql;

uses(RefreshGuardedMySql::class);

test('initialization before general seeding installs both roles without creating ordinary accounts', function () {
    config(['identity.bootstrap' => ['name' => 'Initial Owner', 'email' => 'owner@example.test', 'password' => 'adequate password']]);

    $this->artisan('expedflow:admin:init')->assertSuccessful();
    $admin = User::query()->sole();
    $ids = Role::query()->pluck('id', 'name')->all();
    $this->seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(1)
        ->and(Role::query()->pluck('id', 'name')->all())->toBe($ids)
        ->and($admin->roles()->pluck('name')->all())->toBe(['admin'])
        ->and($admin->getAllPermissions())->toHaveCount(count(PermissionKey::cases()));
    $this->assertDatabaseMissing('seeded_catalogs', ['catalog' => 'authorization_admin_pending']);
});

test('upgrading D grants ordinary membership once while preserving accounts and custom associations', function () {
    $adminRole = Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
    $custom = Role::query()->create(['name' => 'Case reviewer', 'guard_name' => 'web']);
    $legacy = Permission::query()->create(['name' => 'legacy.view', 'guard_name' => 'web']);
    $custom->givePermissionTo($legacy);
    $admin = User::factory()->create()->assignRole($adminRole);
    $disabledAdmin = User::factory()->create(['disabled_at' => now()])->assignRole($adminRole);
    $ordinary = User::factory()->create(['locale' => 'ca', 'auth_version' => 3])->assignRole($custom)->givePermissionTo($legacy);
    $disabled = User::factory()->create(['disabled_at' => now()]);
    $task = Task::factory()->create();
    $task->assignees()->attach($ordinary);
    $attributes = $ordinary->fresh()->getRawOriginal();

    $this->seed(AuthorizationSeeder::class);
    $ordinaryRole = Role::query()->where('name', 'user')->sole();
    expect($ordinary->roles()->orderBy('name')->pluck('name')->all())->toBe(['Case reviewer', 'user'])
        ->and($disabled->roles()->pluck('name')->all())->toBe(['user'])
        ->and($admin->roles()->sole()->is($adminRole))->toBeTrue()
        ->and($disabledAdmin->roles()->sole()->is($adminRole))->toBeTrue()
        ->and($disabled->fresh()->disabled_at)->not->toBeNull()
        ->and($ordinary->fresh()->getRawOriginal())->toBe($attributes)
        ->and($ordinary->permissions()->sole()->is($legacy))->toBeTrue()
        ->and($custom->permissions()->sole()->is($legacy))->toBeTrue()
        ->and($task->assignees()->sole()->is($ordinary))->toBeTrue()
        ->and($ordinaryRole->permissions()->count())->toBe(count(PermissionKey::cases()) - 7);
    $ids = Permission::query()->pluck('id', 'name')->all();

    $ordinary->removeRole($ordinaryRole);
    $ordinaryRole->permissions()->detach();
    $adminRole->permissions()->detach();
    $this->seed(AuthorizationSeeder::class);

    expect($ordinary->roles()->sole()->is($custom))->toBeTrue()
        ->and($ordinaryRole->permissions()->count())->toBe(0)
        ->and($adminRole->permissions()->count())->toBe(7)
        ->and(Permission::query()->pluck('id', 'name')->all())->toBe($ids);
});

test('a preseeded role cannot take over an existing account and the owner may retry with a new identity', function () {
    $existing = User::factory()->create(['email' => 'ordinary@example.test']);
    $this->seed(AuthorizationSeeder::class);
    config(['identity.bootstrap' => ['name' => 'Initial Owner', 'email' => $existing->email, 'password' => 'adequate password']]);

    $this->artisan('expedflow:admin:init')->assertFailed();

    expect($existing->roles()->pluck('name')->all())->toBe(['user']);
    $this->assertDatabaseHas('seeded_catalogs', ['catalog' => 'authorization_admin_pending']);
    config(['identity.bootstrap.email' => 'new-owner@example.test']);
    $this->artisan('expedflow:admin:init')->assertSuccessful();
    expect(DB::table('seeded_catalogs')->where('catalog', 'authorization_admin_pending')->exists())->toBeFalse();
});

test('seeding preserves a preexisting exact ordinary role and its restricted business baseline', function (): void {
    $role = Role::query()->create(['name' => 'user', 'guard_name' => 'web']);
    $read = Permission::query()->create(['name' => 'clients.view', 'guard_name' => 'web']);
    $role->givePermissionTo($read);
    $account = User::factory()->create();

    $this->seed(AuthorizationSeeder::class);

    expect($account->roles()->sole()->is($role))->toBeTrue()
        ->and($role->permissions()->sole()->is($read))->toBeTrue();
});

test('a legacy case variant of a reserved role aborts the catalog transaction', function (): void {
    $legacy = Role::query()->create(['name' => 'Usér', 'guard_name' => 'web']);
    expect(fn () => $this->seed(AuthorizationSeeder::class))
        ->toThrow(LogicException::class, 'Reserved ordinary role name requires owner review.');

    expect(Role::query()->sole()->is($legacy))->toBeTrue();
    $this->assertDatabaseCount('permissions', 0);
    $this->assertDatabaseCount('seeded_catalogs', 0);
});
