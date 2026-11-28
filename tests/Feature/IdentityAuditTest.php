<?php

use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\IdentityHttp;
use Tests\Support\RefreshGuardedMySql;

uses(IdentityHttp::class, RefreshGuardedMySql::class);

beforeEach(function () {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('bearer account mutations identify the explicit administrator and redact credentials', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $target = User::factory()->create(['name' => 'Before', 'email' => 'private-before@example.test']);
    $auth = ['Authorization' => 'Bearer '.$admin->createToken('Private admin device')->plainTextToken];
    $path = "/api/v1/users/{$target->id}";
    $changes = ['name' => 'After', 'email' => 'private-after@example.test'];
    $password = 'synthetic private password';
    $this->api('PATCH', $path, $changes, $auth)->assertOk();
    $this->api('PATCH', $path, $changes, $auth)->assertOk();
    $this->api('PATCH', $path.'/password', ['password' => $password, 'password_confirmation' => $password], $auth)->assertOk();
    $this->api('POST', $path.'/disable', headers: $auth)->assertOk();
    $this->api('POST', $path.'/disable', headers: $auth)->assertOk();
    $this->api('POST', $path.'/enable', headers: $auth)->assertOk();
    $this->api('GET', $path, headers: $auth)->assertOk();

    $rows = Activity::query()->orderBy('id')->get();
    expect($rows->pluck('event')->all())->toBe(['user.updated', 'user.password_changed', 'user.disabled', 'user.enabled']);
    expect($rows->pluck('causer_id')->unique()->values()->all())->toBe([$admin->id]);
    expect($rows->pluck('subject_id')->unique()->values()->all())->toBe([$target->id]);
    expect($rows[0]->attribute_changes->get('old'))->toBe(['name' => 'Before']);
    expect($rows[0]->attribute_changes->get('attributes'))->toEqual(['name' => 'After', 'email' => ['changed' => true]]);
    expect(json_encode(DB::table('activity_log')->get()))->not->toContain('private-before', 'private-after', $password, $auth['Authorization']);
});

test('a browser administrator can change a role once and cannot act without CSRF', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
    $this->spa('POST', '/roles', ['name' => 'Dispatch', 'permissions' => ['cases.view']])->assertUnprocessable();
    $role = $this->spa('POST', '/roles', ['name' => 'Dispatch', 'permissions' => ['tasks.view']])->assertCreated()->json('data.id');
    $this->api('PUT', "/api/v1/roles/$role/permissions", ['permissions' => []], browser: true)->assertStatus(419);
    $this->spa('PUT', "/roles/$role/permissions", ['permissions' => ['tasks.update']])->assertOk();
    $this->spa('PUT', "/roles/$role/permissions", ['permissions' => ['tasks.update']])->assertOk();
    $this->spa('DELETE', "/roles/$role")->assertOk();

    $rows = Activity::query()->orderBy('id')->get();
    expect($rows->pluck('event')->all())->toBe(['role.created', 'role.permissions_changed', 'role.deleted']);
    expect($rows[1]->subject_id)->toBe($role);
    expect($rows[1]->causer_id)->toBe($admin->id);
    expect($rows[1]->getProperty('metadata'))->toEqual(['added_permissions' => ['tasks.update'], 'removed_permissions' => ['tasks.view']]);
});

test('a failed audit insert preserves the password and existing credentials', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $target = User::factory()->create()->refresh();
    $targetToken = $target->createToken('Existing client');
    $auth = ['Authorization' => 'Bearer '.$admin->createToken('Admin')->plainTextToken];
    Activity::saving(fn () => false);

    $this->api('PATCH', "/api/v1/users/{$target->id}/password", [
        'password' => 'synthetic replaced password', 'password_confirmation' => 'synthetic replaced password',
    ], $auth)->assertInternalServerError()->assertJsonPath('code', 'server_error');

    expect($target->fresh()->password)->toBe($target->password);
    expect($target->fresh()->auth_version)->toBe($target->auth_version);
    $this->assertModelExists($targetToken->accessToken);
    expect(Activity::query()->count())->toBe(0);
});

test('token issuance and revocation and verified recovery use safe historical account identity', function () {
    $account = User::factory()->create(['email' => 'synthetic-private@example.test']);
    $token = $this->api('POST', '/api/v1/auth/token', [
        'email' => $account->email, 'password' => 'password', 'device_name' => 'private-device-name',
    ])->assertCreated()->json('data.token');
    $this->api('DELETE', '/api/v1/auth/token', headers: ['Authorization' => 'Bearer '.$token])->assertOk();
    $reset = Password::broker()->createToken($account);
    $this->api('POST', '/api/v1/auth/reset-password', [
        'email' => $account->email, 'token' => $reset,
        'password' => 'synthetic recovered password', 'password_confirmation' => 'synthetic recovered password',
    ])->assertOk();

    $rows = Activity::query()->orderBy('id')->get();
    expect($rows->pluck('event')->all())->toBe(['user.token_issued', 'user.token_revoked', 'user.password_recovered']);
    expect($rows[2]->getProperty('origin'))->toBe('password_recovery');
    expect($rows->pluck('causer_id')->unique()->values()->all())->toBe([$account->id]);
    expect(json_encode(DB::table('activity_log')->get()))->not->toContain($token, $reset, $account->email, 'private-device-name', 'synthetic recovered password');
});
