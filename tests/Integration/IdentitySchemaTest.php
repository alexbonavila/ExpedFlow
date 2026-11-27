<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\RefreshGuardedMySql;

uses(RefreshGuardedMySql::class);

test('the user language preference persists independently per account', function (string $locale) {
    $user = User::factory()->create(['locale' => $locale]);

    expect($user->refresh()->locale)->toBe($locale);
})->with(['es', 'ca', 'en']);

test('MySQL defaults to Spanish and rejects unsupported or incorrectly cased locales', function () {
    $user = User::factory()->create()->refresh();

    expect($user->locale)->toBe('es');
    foreach (['fr', 'EN'] as $invalid) {
        expect(fn () => DB::table('users')->where('id', $user->id)->update(['locale' => $invalid]))
            ->toThrow(QueryException::class, 'users_locale_check');
    }
});

test('the published package schemas persist role, permission and hashed token associations', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Synthetic operator', 'guard_name' => 'web']);
    $rolePermission = Permission::create(['name' => 'synthetic cases', 'guard_name' => 'web']);
    $directPermission = Permission::create(['name' => 'synthetic tasks', 'guard_name' => 'web']);
    $role->givePermissionTo($rolePermission);
    $user->assignRole($role);
    $user->givePermissionTo($directPermission);
    $issued = $user->createToken('Synthetic client', ['cases:read']);

    expect($user->roles()->sole()->is($role))->toBeTrue();
    expect($role->permissions()->sole()->is($rolePermission))->toBeTrue();
    expect($user->permissions()->sole()->is($directPermission))->toBeTrue();

    $token = $user->tokens()->sole();
    expect($token->is($issued->accessToken))->toBeTrue();
    expect($token->abilities)->toBe(['cases:read']);
    expect($token->token)->toBe(hash('sha256', Str::after($issued->plainTextToken, '|')));
    expect($token->toArray())->not->toHaveKey('token');
});
