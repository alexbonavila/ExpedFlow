<?php

use App\Models\User;
use App\Support\AccountLifecycle;
use App\Support\IdentityError;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\IdentityHttp;
use Tests\Support\RefreshGuardedMySql;

uses(IdentityHttp::class, RefreshGuardedMySql::class);

beforeEach(function () {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

function managementAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate(User::ADMIN_ROLE, 'web'));

    return $admin;
}

test('every management route denies unauthenticated and ordinary clients', function () {
    $ordinary = User::factory()->create();
    $ordinary->assignRole('user')->givePermissionTo([
        Permission::findOrCreate('manage-accounts', 'web'), Permission::findByName('users.manage', 'web'),
    ]);
    $target = User::factory()->create();
    $bearer = ['Authorization' => 'Bearer '.$ordinary->createToken('Ordinary client', ['*'])->plainTextToken];
    $this->api('GET', '/api/v1/users/999999')->assertUnauthorized();
    $this->api('GET', '/api/v1/users/999999', headers: $bearer)->assertForbidden();
    foreach ([
        ['GET', '/users'], ['POST', '/users'], ['GET', "/users/{$target->id}"],
        ['PATCH', "/users/{$target->id}"], ['PATCH', "/users/{$target->id}/password"],
        ['POST', "/users/{$target->id}/disable"], ['POST', "/users/{$target->id}/enable"],
    ] as [$method, $path]) {
        $this->api($method, '/api/v1'.$path)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->api($method, '/api/v1'.$path, headers: $bearer)->assertForbidden()->assertJsonPath('code', 'forbidden');
    }
});

test('administrators manage ordinary accounts with explicit fields and a complete safe list', function () {
    $admin = managementAdmin();
    $auth = ['Authorization' => 'Bearer '.$admin->createToken('Admin fixture', ['identity'])->plainTextToken];
    User::factory()->count(16)->create();
    $input = [
        'name' => '  Synthetic Name  ', 'email' => '  NEW@example.test  ', 'password' => 'synthetic long password',
        'role' => 'admin', 'permissions' => ['*'], 'id' => 50000, 'disabled_at' => now()->toISOString(),
        'email_verified_at' => now()->toISOString(), 'remember_token' => 'ignored',
    ];
    $response = $this->api('POST', '/api/v1/users', $input, $auth)->assertCreated();
    $id = $response->json('data.id');
    $response->assertExactJson(['data' => ['id' => $id, 'name' => $input['name'], 'email' => 'new@example.test', 'locale' => 'es', 'active' => true]]);
    $user = User::query()->findOrFail($id);
    expect($user->roles()->pluck('name')->all())->toBe(['user'])
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->remember_token)->toBeNull()
        ->and(Hash::check($input['password'], $user->password))->toBeTrue();

    $this->api('GET', '/api/v1/users', headers: $auth)->assertOk()->assertJsonCount(18, 'data')
        ->assertJsonMissingPath('links')->assertJsonMissingPath('meta')->assertJsonMissingPath('data.0.password')
        ->assertJsonPath('data.0.id', $admin->id);
    $this->api('GET', "/api/v1/users/$id", headers: $auth)->assertOk()->assertExactJson($response->json());
    $this->api('GET', '/api/v1/users/99999', headers: $auth)->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->api('POST', '/api/v1/users', $input, $auth)->assertConflict()->assertJsonPath('code', 'email_conflict');
    $this->api('POST', '/api/v1/users', [...$input, 'locale' => 'ru', 'password' => 'too short'], $auth)
        ->assertUnprocessable()->assertJsonValidationErrors(['locale', 'password']);
    $this->api('DELETE', "/api/v1/users/$id", headers: $auth)->assertMethodNotAllowed();
    $this->api('POST', "/api/v1/users/$id/roles", ['role' => 'admin'], $auth)->assertMethodNotAllowed();
    $this->assertModelExists($user);
});

test('managed updates normalize email, revoke the old reset token and preserve explicit allowlists', function () {
    $admin = managementAdmin();
    $auth = ['Authorization' => 'Bearer '.$admin->createToken('Admin fixture')->plainTextToken];
    $user = User::factory()->create();
    Password::broker()->createToken($user);
    $this->api('PATCH', "/api/v1/users/{$user->id}", ['name' => 'Edited'], $auth)->assertOk()->assertJsonPath('data.name', 'Edited');
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    $this->api('PATCH', "/api/v1/users/{$user->id}", ['email' => $user->email], $auth)->assertOk();
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

    $this->api('PATCH', "/api/v1/users/{$user->id}", ['email' => strtoupper($admin->email)], $auth)
        ->assertConflict()->assertJsonPath('code', 'email_conflict');
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);

    $this->api('PATCH', "/api/v1/users/{$user->id}", [
        'email' => '  NEW@example.test ', 'locale' => 'en', 'password' => 'ignored password',
        'role' => 'admin', 'disabled_at' => now()->toISOString(),
    ], $auth)->assertOk()->assertJsonPath('data.email', 'new@example.test')->assertJsonPath('data.locale', 'en');
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    expect($user->fresh()->email_verified_at)->toBeNull()
        ->and($user->fresh()->password)->toBe($user->password)
        ->and($user->fresh()->disabled_at)->toBeNull()
        ->and($user->fresh()->roles)->toBeEmpty();
});

test('disabling an account revokes credentials and re-enabling does not restore them', function () {
    $admin = managementAdmin();
    $auth = ['Authorization' => 'Bearer '.$admin->createToken('Admin fixture')->plainTextToken];
    $user = User::factory()->create();
    $user->assignRole(Role::query()->create(['name' => 'Minimal client', 'guard_name' => 'web']));
    $user->givePermissionTo(Permission::findOrCreate('tasks.update', 'web'));
    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()->assertJsonPath('data.permissions', ['tasks.update']);
    $session = DB::table('sessions')->where('user_id', $user->id)->sole();
    $mobile = $user->createToken('Stale client');
    Password::broker()->createToken($user);

    $this->api('POST', "/api/v1/users/{$admin->id}/disable", headers: $auth)->assertConflict()->assertJsonPath('code', 'self_disable');
    $this->api('POST', "/api/v1/users/{$user->id}/disable", headers: $auth)->assertOk()->assertJsonPath('data.active', false);
    $this->api('POST', "/api/v1/users/{$user->id}/disable", headers: $auth)->assertOk()->assertJsonPath('data.active', false);
    expect($user->fresh()->auth_version)->toBe(1)
        ->and($user->fresh()->remember_token)->toBeNull()
        ->and($user->fresh()->hasPermissionTo('tasks.update'))->toBeTrue();
    $this->assertModelMissing($mobile->accessToken);
    $this->assertDatabaseMissing('sessions', ['id' => $session->id]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

    $this->api('POST', "/api/v1/users/{$user->id}/enable", headers: $auth)->assertOk()->assertJsonPath('data.active', true);
    DB::table('sessions')->insert((array) $session);
    $this->spa('GET', '/me')->assertUnauthorized();
    $this->api('GET', '/api/v1/me', headers: ['Authorization' => 'Bearer '.$mobile->plainTextToken])->assertUnauthorized();

    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
});

test('administrator password replacement revokes credentials and hashes the new password', function () {
    $admin = managementAdmin();
    $auth = ['Authorization' => 'Bearer '.$admin->createToken('Admin fixture')->plainTextToken];
    $user = User::factory()->create();
    $oldToken = $user->createToken('Old client');
    $password = 'synthetic new password';
    $this->api('PATCH', "/api/v1/users/{$user->id}/password", ['password' => $password], $auth)
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->api('PATCH', "/api/v1/users/{$user->id}/password", ['password' => $password, 'password_confirmation' => $password], $auth)
        ->assertOk()->assertJsonPath('code', 'password_replaced')->assertJsonStructure(['code', 'message']);
    expect(Hash::check($password, $user->fresh()->password))->toBeTrue();
    $this->assertModelMissing($oldToken->accessToken);
});

test('an already admitted stale administrative operation cannot disable the last active administrator', function () {
    $active = managementAdmin();
    $other = managementAdmin();
    $other->forceFill(['disabled_at' => now()])->save();

    expect(fn () => app(AccountLifecycle::class)->disable($other, $active))->toThrow(
        fn (IdentityError $exception) => expect($exception->errorCode)->toBe('unauthenticated')
    );
    expect($active->fresh()->disabled_at)->toBeNull();

    $ordinary = User::factory()->create();
    expect(fn () => app(AccountLifecycle::class)->disable($other, $ordinary))->toThrow(
        fn (IdentityError $exception) => expect($exception->errorCode)->toBe('unauthenticated')
    );
    expect($ordinary->fresh()->disabled_at)->toBeNull();
});

test('management validates account name, email and password boundaries before saving', function () {
    $admin = managementAdmin();
    $auth = ['Authorization' => 'Bearer '.$admin->createToken('Admin fixture')->plainTextToken];
    $valid = ['name' => 'Synthetic account', 'email' => 'limit@example.test', 'password' => 'synthetic long password'];
    foreach ([
        ['name' => str_repeat('a', 256)],
        ['name' => []],
        ['name' => '   '],
        ['email' => 'invalid'],
        ['email' => str_repeat('a', 240).'@long-example.test'],
        ['email' => []],
        ['password' => []],
    ] as $override) {
        $this->api('POST', '/api/v1/users', $override + $valid, $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($override));
    }
    $this->api('POST', '/api/v1/users', [], $auth)->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
    $this->assertDatabaseCount('users', 1);
});

test('managed account updates validate each individual field and preserve the original record', function () {
    $admin = managementAdmin();
    $user = User::factory()->create()->refresh();
    $original = $user->getRawOriginal();
    $auth = ['Authorization' => 'Bearer '.$admin->createToken('Admin fixture')->plainTextToken];
    foreach ([
        ['name' => '   '], ['name' => 1234], ['name' => str_repeat('n', 256)],
        ['email' => 'invalid'], ['email' => str_repeat('e', 247).'@example.test'],
        ['locale' => 'zz'], ['locale' => ['es']], ['locale' => null],
    ] as $invalid) {
        $this->api('PATCH', "/api/v1/users/{$user->id}", $invalid, $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
    }
    expect($user->refresh()->getRawOriginal())->toBe($original);
});

test('account routes return 403 when an administrator lacks the explicit management permission', function (): void {
    $admin = managementAdmin();
    $headers = ['Authorization' => 'Bearer '.$admin->createToken('Admin fixture')->plainTextToken];
    $admin->roles()->sole()->revokePermissionTo('users.manage');

    $this->api('GET', '/api/v1/users', headers: $headers)->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->api('POST', '/api/v1/users', headers: $headers)->assertForbidden();
    $this->assertDatabaseCount('users', 1);
});
