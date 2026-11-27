<?php

use App\Models\User;
use App\Support\AdministrativeWrite;
use App\Support\EffectivePermissions;
use App\Support\IdentityError;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\IdentityHttp;
use Tests\Support\TestingDatabase;

uses(IdentityHttp::class);

beforeEach(function () {
    TestingDatabase::assertSafe($this->app);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->seed(AuthorizationSeeder::class);
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
    $role = Role::findByName(User::ADMIN_ROLE, 'web');
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

    expect($response)->toBe(['status' => 401, 'code' => 'unauthenticated']);
    expect(User::role($role)->whereNull('disabled_at')->sole()->id)->toBe($first->id);
    $this->assertModelMissing($secondToken->accessToken);
});

test('a concurrent token issuance resumes after disable and cannot issue a credential', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findByName(User::ADMIN_ROLE, 'web'));
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

test('competing administrator mutations recheck current membership and account state under the same lock', function (
    string $winningAction, string $contendingAction, int $denial, string $code,
): void {
    $role = Role::findByName('admin', 'web');
    $first = User::factory()->create()->assignRole($role);
    $second = User::factory()->create()->assignRole($role);
    $firstAuth = 'Bearer '.$first->createToken('First actor')->plainTextToken;
    $secondAuth = 'Bearer '.$second->createToken('Second actor')->plainTextToken;
    $method = fn (string $action): string => $action === 'roles' ? 'PUT' : 'POST';
    $payload = fn (string $action): array => $action === 'roles' ? ['role_ids' => []] : [];

    DB::beginTransaction();
    Role::query()->lockForUpdate()->findOrFail($role->id);
    $response = competeForIdentityLock([
        'method' => $method($contendingAction), 'path' => "/api/v1/users/{$first->id}/$contendingAction",
        'authorization' => $secondAuth, 'body' => $payload($contendingAction),
    ], function () use ($firstAuth, $second, $winningAction, $method, $payload): void {
        $this->api($method($winningAction), "/api/v1/users/{$second->id}/$winningAction", $payload($winningAction),
            ['Authorization' => $firstAuth])->assertOk();
    });

    expect($response)->toBe(['status' => $denial, 'code' => $code])
        ->and(User::role($role)->whereNull('disabled_at')->sole()->is($first))->toBeTrue();
})->with([
    'demotion against demotion' => ['roles', 'roles', 403, 'forbidden'],
    'disable against demotion' => ['disable', 'roles', 401, 'unauthenticated'],
    'demotion against disable' => ['roles', 'disable', 403, 'forbidden'],
]);

test('an admitted password replacement fails after the actor loses administrator authority', function (): void {
    $role = Role::findByName('admin', 'web');
    $owner = User::factory()->create()->assignRole($role);
    $actor = User::factory()->create()->assignRole($role);
    $victim = User::factory()->create();
    $password = $victim->password;
    $ownerAuth = 'Bearer '.$owner->createToken('Owner')->plainTextToken;
    $actorAuth = 'Bearer '.$actor->createToken('Admitted writer')->plainTextToken;
    $victimToken = $victim->createToken('Existing credential');

    DB::beginTransaction();
    Role::query()->lockForUpdate()->findOrFail($role->id);
    $response = competeForIdentityLock([
        'method' => 'PATCH', 'path' => "/api/v1/users/{$victim->id}/password",
        'authorization' => $actorAuth, 'body' => [
            'password' => 'synthetic password change', 'password_confirmation' => 'synthetic password change',
        ],
    ], function () use ($ownerAuth, $actor): void {
        $this->api('PUT', "/api/v1/users/{$actor->id}/roles", ['role_ids' => []], ['Authorization' => $ownerAuth])->assertOk();
    });

    expect($response)->toBe(['status' => 403, 'code' => 'forbidden'])
        ->and($victim->fresh()->password)->toBe($password);
    $this->assertModelExists($victimToken->accessToken);
});

test('a concurrent password change invalidates the admitted administrative credential generation', function (): void {
    $role = Role::findByName('admin', 'web');
    $owner = User::factory()->create()->assignRole($role);
    $actor = User::factory()->create()->assignRole($role);
    $ownerAuth = 'Bearer '.$owner->createToken('Owner')->plainTextToken;
    $actorAuth = 'Bearer '.$actor->createToken('Admitted writer')->plainTextToken;
    $account = User::factory()->create();

    DB::beginTransaction();
    Role::query()->lockForUpdate()->findOrFail($role->id);
    $response = competeForIdentityLock([
        'method' => 'PATCH', 'path' => "/api/v1/users/{$account->id}",
        'authorization' => $actorAuth, 'body' => ['name' => 'Unauthorized late edit'],
    ], function () use ($ownerAuth, $actor): void {
        $this->api('PATCH', "/api/v1/users/{$actor->id}/password", [
            'password' => 'synthetic password change', 'password_confirmation' => 'synthetic password change',
        ], ['Authorization' => $ownerAuth])->assertOk();
    });

    expect($response)->toBe(['status' => 401, 'code' => 'unauthenticated'])
        ->and($account->fresh()->name)->toBe($account->name);
});

test('role assignment racing deletion preserves referential and authorization state', function (bool $assignmentFirst): void {
    $actor = User::factory()->create()->assignRole('admin');
    $user = User::factory()->create();
    $role = Role::query()->create(['name' => 'Temporary reviewer', 'guard_name' => 'web']);
    $headers = ['Authorization' => 'Bearer '.$actor->createToken('Concurrent client')->plainTextToken];
    $assignment = [
        'method' => 'PUT', 'path' => "/api/v1/users/{$user->id}/roles", 'body' => ['role_ids' => [$role->id]],
    ];
    $deletion = ['method' => 'DELETE', 'path' => "/api/v1/roles/{$role->id}", 'body' => []];
    [$winner, $contender] = $assignmentFirst ? [$assignment, $deletion] : [$deletion, $assignment];
    DB::beginTransaction();
    Role::query()->lockForUpdate()->findOrFail(Role::findByName('admin', 'web')->id);
    $response = competeForIdentityLock($contender + ['authorization' => $headers['Authorization']], function () use ($winner, $headers): void {
        $this->api($winner['method'], $winner['path'], $winner['body'], $headers)->assertOk();
    });

    if ($assignmentFirst) {
        expect($response)->toBe(['status' => 409, 'code' => 'role_assigned'])
            ->and($user->roles()->sole()->is($role))->toBeTrue();
    } else {
        expect($response)->toBe(['status' => 422, 'code' => 'validation_failed'])
            ->and($user->roles()->count())->toBe(0);
        $this->assertModelMissing($role);
    }
})->with(['assignment wins' => true, 'deletion wins' => false]);

test('competing replacements remain complete across processes and invalidate the database cache after commit', function (): void {
    config(['permission.cache.store' => 'database']);
    $registrar = app(PermissionRegistrar::class);
    $registrar->initializeCache();
    $cachedKey = config()->string('permission.cache.key');
    $role = Role::query()->create(['name' => 'Reviewers', 'guard_name' => 'web']);
    $role->givePermissionTo('tasks.view');
    $account = User::factory()->create()->assignRole($role)->load('roles.permissions');
    $actor = User::factory()->create()->assignRole('admin');
    $headers = ['Authorization' => 'Bearer '.$actor->createToken('Grant writer')->plainTextToken];
    $update = "/api/v1/roles/{$role->id}/permissions";
    Permission::findByName('tasks.view', 'web');
    expect(Cache::store('database')->has($cachedKey))->toBeTrue();

    DB::beginTransaction();
    $this->api('PUT', $update, ['permissions' => ['tasks.create']], $headers)->assertOk();
    expect(Cache::store('database')->has($cachedKey))->toBeTrue();
    DB::rollBack();
    expect(Cache::store('database')->has($cachedKey))->toBeTrue()
        ->and($role->permissions()->pluck('name')->all())->toBe(['tasks.view']);

    DB::beginTransaction();
    Role::query()->lockForUpdate()->findOrFail(Role::findByName('admin', 'web')->id);
    $response = competeForIdentityLock([
        'method' => 'PUT', 'path' => $update, 'authorization' => $headers['Authorization'],
        'body' => ['permissions' => ['tags.view', 'expedients.view']],
    ], function () use ($update, $headers): void {
        $this->api('PUT', $update, ['permissions' => ['tasks.create']], $headers)->assertOk();
    });
    expect($response)->toBe(['status' => 200, 'code' => null])
        ->and(Cache::store('database')->has($cachedKey))->toBeFalse()
        ->and(app(EffectivePermissions::class)->for($account))->toBe(['expedients.view', 'tags.view']);

    $process = new Process(['php', 'tests/Fixtures/IdentityContender.php'], base_path(), ['APP_ENV' => 'testing']);
    $process->setTimeout(15)->setInput(json_encode([
        'method' => 'GET', 'path' => '/api/v1/me', 'read_effective' => true,
        'authorization' => 'Bearer '.$account->createToken('Existing reader')->plainTextToken,
    ], JSON_THROW_ON_ERROR));
    expect($process->run())->toBe(0);
    expect(preg_match('/^result:(.+)$/m', $process->getOutput(), $matches))->toBe(1);
    expect(json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR))->toBe([
        'status' => 200, 'code' => null, 'permissions' => ['expedients.view', 'tags.view'],
    ]);
});

test('catalog seeding waits for the first initializer and preserves its administrator', function (): void {
    config(['identity.bootstrap' => [
        'name' => 'First Owner', 'email' => 'first-owner@example.test', 'password' => 'synthetic first password',
    ]]);
    DB::beginTransaction();
    expect(Artisan::call('expedflow:admin:init'))->toBe(0);

    $response = competeForIdentityLock(['seed' => true], fn () => null);

    expect($response)->toBe(['exit' => 0])
        ->and(User::role('admin')->whereNull('disabled_at')->sole()->email)->toBe('first-owner@example.test');
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseMissing('seeded_catalogs', ['catalog' => 'authorization_admin_pending']);
});

test('the shared invariant rejects removal of a sole active administrator despite disabled memberships', function (): void {
    $role = Role::findByName('admin', 'web');
    $active = User::factory()->create()->assignRole($role);
    User::factory()->create(['disabled_at' => now()])->assignRole($role);

    expect(fn () => AdministrativeWrite::assertRemovableAdministrator($role, $active))->toThrow(
        fn (IdentityError $exception) => expect($exception->errorCode)->toBe('last_active_admin'),
    );
    expect(User::role($role)->whereNull('disabled_at')->sole()->is($active))->toBeTrue();
});
