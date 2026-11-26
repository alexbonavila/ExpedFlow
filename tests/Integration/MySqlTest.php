<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestingDatabase;

beforeEach(function () {
    TestingDatabase::assertSafe($this->app);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

test('MySQL 8.4 migrates the scaffold, rolls it back and migrates it again', function () {
    expect(DB::scalar('SELECT VERSION()'))->toStartWith('8.4.');
    expect(Schema::getTableListing(schemaQualified: false))->toContain('users', 'sessions', 'cache', 'jobs');

    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
    foreach (['users', 'sessions', 'cache', 'jobs'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect(Schema::getTableListing(schemaQualified: false))->toContain('users', 'sessions', 'cache', 'jobs');
});

test('the scaffold seeder and factory produce usable users without exposing credentials', function () {
    $this->seed(DatabaseSeeder::class);
    $seeded = User::query()->where('email', 'test@example.com')->sole();
    $user = User::factory()->unverified()->create(['password' => 'synthetic-password']);

    expect($seeded->name)->toBe('Test User');
    expect($user->email_verified_at)->toBeNull();
    expect(Hash::check('synthetic-password', $user->password))->toBeTrue();
    expect($user->toArray())->not->toHaveKeys(['password', 'remember_token']);
    $this->assertModelExists($user);
});

test('the scaffold enforces unique user emails in MySQL', function () {
    $user = User::factory()->create();

    expect(fn () => User::factory()->create(['email' => $user->email]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('independent connections respect transaction isolation and observe an InnoDB lock wait', function () {
    $user = User::factory()->create(['name' => 'Before']);
    config(['database.connections.phase_b_observer' => config('database.connections.mysql')]);
    $writer = DB::connection('mysql');
    $observer = DB::connection('phase_b_observer');
    expect($writer->scalar('SELECT CONNECTION_ID()'))->not->toBe($observer->scalar('SELECT CONNECTION_ID()'));

    $writer->beginTransaction();
    $writer->table('users')->where('id', $user->id)->update(['name' => 'Uncommitted']);

    try {
        expect($observer->table('users')->where('id', $user->id)->value('name'))->toBe('Before');
        $observer->statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $observer->table('users')->where('id', $user->id)->update(['name' => 'Contender']);
            test()->fail('The second connection unexpectedly wrote to a locked row.');
        } catch (QueryException $exception) {
            expect((int) $exception->errorInfo[1])->toBe(1205);
        }
    } finally {
        $writer->rollBack();
        DB::purge('phase_b_observer');
    }

    $committed = DB::connection('phase_b_observer');
    try {
        expect($committed->table('users')->where('id', $user->id)->value('name'))->toBe('Before');
        $writer->transaction(fn () => $writer->table('users')->where('id', $user->id)->update(['name' => 'Committed']));
        expect($committed->table('users')->where('id', $user->id)->value('name'))->toBe('Committed');
    } finally {
        DB::purge('phase_b_observer');
    }
});
