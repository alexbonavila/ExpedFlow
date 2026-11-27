<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\Support\IdentityHttp;
use Tests\Support\TestingDatabase;

uses(IdentityHttp::class);

beforeEach(function () {
    TestingDatabase::assertSafe($this->app);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->identityHttp();
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

function competeForIdentityLock(array $operation, Closure $whileWaiting): array
{
    $process = new Process(['php', 'tests/Fixtures/IdentityContender.php'], base_path(), ['APP_ENV' => 'testing']);
    $process->setTimeout(15)->setInput(json_encode($operation, JSON_THROW_ON_ERROR));
    $process->start();

    try {
        expect($process->waitUntil(fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "ready\n")))
            ->toBeTrue();
        $whileWaiting();
        DB::commit();

        expect($process->wait())->toBe(0);
        expect(preg_match('/^result:(.+)$/m', $process->getOutput(), $matches))->toBe(1);

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $process->stop();
    }
}

test('competing administrator initialization cannot create a second account', function () {
    $first = ['name' => 'First Contender', 'email' => 'first@example.test', 'password' => 'synthetic initial value'];
    config(['identity.bootstrap' => $first]);
    DB::beginTransaction();
    expect(Artisan::call('expedflow:admin:init'))->toBe(0);

    $response = competeForIdentityLock(['bootstrap' => [...$first, 'email' => 'second@example.test']], fn () => null);
    expect($response)->toBe(['exit' => 1]);
    expect(User::role(User::ADMIN_ROLE)->sole()->email)->toBe($first['email']);
    expect(User::query()->count())->toBe(1);
});

test('two reset requests cannot consume the same reset token', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);
    $first = ['email' => $user->email, 'token' => $token, 'password' => 'synthetic first reset', 'password_confirmation' => 'synthetic first reset'];
    $second = [...$first, 'password' => 'synthetic second reset', 'password_confirmation' => 'synthetic second reset'];
    DB::beginTransaction();
    $this->api('POST', '/api/v1/auth/reset-password', $first)->assertOk();

    $response = competeForIdentityLock(['path' => '/api/v1/auth/reset-password', 'body' => $second], fn () => null);
    expect($response)->toBe(['status' => 422, 'code' => 'invalid_reset_token']);
    expect(Hash::check($first['password'], $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseEmpty('password_reset_tokens');
});

test('simultaneous administrative disables leave one active administrator', function () {
    $role = Role::create(['name' => User::ADMIN_ROLE, 'guard_name' => 'web']);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $first->assignRole($role);
    $second->assignRole($role);
    $firstToken = $first->createToken('First administrator');
    $secondToken = $second->createToken('Second administrator');

    DB::beginTransaction();
    Role::query()->lockForUpdate()->findOrFail($role->id);
    $response = competeForIdentityLock([
        'path' => "/api/v1/users/{$first->id}/disable",
        'authorization' => 'Bearer '.$secondToken->plainTextToken,
        'body' => [],
    ], function () use ($firstToken, $second): void {
        $this->api('POST', "/api/v1/users/{$second->id}/disable", headers: ['Authorization' => 'Bearer '.$firstToken->plainTextToken])
            ->assertOk()->assertJsonPath('data.active', false);
    });

    expect($response)->toBe(['status' => 409, 'code' => 'last_active_admin']);
    expect(User::role($role)->whereNull('disabled_at')->sole()->id)->toBe($first->id);
    $this->assertModelMissing($secondToken->accessToken);
});

test('a concurrent token issuance resumes after disable and cannot issue a credential', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::create(['name' => User::ADMIN_ROLE, 'guard_name' => 'web']));
    $auth = ['Authorization' => 'Bearer '.$admin->createToken('First administrator')->plainTextToken];
    $user = User::factory()->create();

    DB::beginTransaction();
    $this->api('POST', "/api/v1/users/{$user->id}/disable", headers: $auth)->assertOk();
    $response = competeForIdentityLock([
        'path' => '/api/v1/auth/token',
        'body' => ['email' => $user->email, 'password' => 'password', 'device_name' => 'Contending device'],
    ], fn () => null);

    expect($response)->toBe(['status' => 401, 'code' => 'invalid_credentials']);
    expect($user->tokens()->count())->toBe(0);
});
