<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\Support\RefreshGuardedMySql;

uses(RefreshGuardedMySql::class);

test('only the explicit initializer creates the first administrator and a rerun preserves credentials', function () {
    $this->seed(DatabaseSeeder::class);
    expect(User::query()->count())->toBe(0);
    expect(Role::query()->count())->toBe(0);
    config(['identity.bootstrap' => ['name' => 'First Administrator', 'email' => '  INITIAL@example.test ', 'password' => 'synthetic first password']]);

    $this->artisan('expedflow:admin:init')->expectsOutputToContain('Administrator initialized.')->assertSuccessful();
    $user = User::query()->sole();
    expect($user->email)->toBe('initial@example.test')
        ->and($user->locale)->toBe('es')
        ->and($user->hasRole(User::ADMIN_ROLE, 'web'))->toBeTrue()
        ->and(Hash::check('synthetic first password', $user->password))->toBeTrue();

    config(['identity.bootstrap.password' => 'synthetic changed input']);
    $this->artisan('expedflow:admin:init')->expectsOutputToContain('already initialized')->assertSuccessful();
    expect($user->fresh()->password)->toBe($user->password);
    config(['identity.bootstrap.email' => 'different@example.test']);
    $this->artisan('expedflow:admin:init')->expectsOutputToContain('refused')->assertFailed();
    expect(User::query()->count())->toBe(1);
});

test('initializer refuses unsafe values or an ambiguous reserved role', function (array $values, bool $withRole) {
    if ($withRole) {
        Role::create(['name' => User::ADMIN_ROLE, 'guard_name' => 'web']);
    }
    config(['identity.bootstrap' => $values]);

    $this->artisan('expedflow:admin:init')->assertFailed();
    expect(User::query()->count())->toBe(0);
    expect(Role::query()->count())->toBe((int) $withRole);
})->with([
    'missing' => [[], false],
    'invalid email' => [['name' => 'Owner', 'email' => 'wrong', 'password' => 'adequate password'], false],
    'short password' => [['name' => 'Owner', 'email' => 'first@example.test', 'password' => 'short'], false],
    'bcrypt byte limit' => [['name' => 'Owner', 'email' => 'first@example.test', 'password' => str_repeat('ñ', 37)], false],
    'unsupported bytes' => [['name' => 'Owner', 'email' => 'first@example.test', 'password' => "synthetic password\0"], false],
    'preexisting role' => [['name' => 'Owner', 'email' => 'first@example.test', 'password' => 'adequate password'], true],
]);

test('an email collision never elevates an unrelated account', function () {
    $user = User::factory()->create(['email' => 'ordinary@example.test']);
    config(['identity.bootstrap' => ['name' => 'Owner', 'email' => $user->email, 'password' => 'adequate password']]);

    $this->artisan('expedflow:admin:init')->assertFailed();
    expect($user->fresh()->roles)->toBeEmpty();
    expect($user->fresh()->password)->toBe($user->password);
    expect(Role::query()->count())->toBe(0);
});

test('a failed initializer transaction rolls back the account and role together', function () {
    config(['identity.bootstrap' => ['name' => 'Owner', 'email' => 'first@example.test', 'password' => 'adequate password']]);
    Event::listen('eloquent.created: '.User::class, function (): never {
        throw new RuntimeException('Synthetic account persistence failure.');
    });

    expect(fn () => Artisan::call('expedflow:admin:init'))->toThrow(RuntimeException::class, 'Synthetic account persistence failure.');
    expect(User::query()->count())->toBe(0);
    expect(Role::query()->count())->toBe(0);
});
