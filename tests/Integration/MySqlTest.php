<?php

use App\Models\ExpedientSequence;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestingDatabase;

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

beforeEach(function () {
    TestingDatabase::assertSafe($this->app);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
});

test('MySQL 8.4 migrates the complete schema from empty and after rollback', function () {
    expect(DB::scalar('SELECT VERSION()'))->toStartWith('8.4.');
    $tables = [
        'activity_log', 'cache', 'cache_locks', 'clients', 'expedient_sequences',
        'expedient_tag', 'expedient_types', 'expedient_user_preferences', 'expedients',
        'failed_jobs', 'incidents', 'job_batches', 'jobs', 'model_has_permissions',
        'model_has_roles', 'password_reset_tokens', 'permissions', 'personal_access_tokens',
        'role_has_permissions', 'roles', 'seeded_catalogs', 'sessions', 'statuses',
        'tags', 'task_checklist_items', 'task_comments', 'task_template_checklist_items',
        'task_templates', 'task_user', 'tasks', 'users',
    ];

    expect(Schema::getTableListing(schemaQualified: false))->toEqualCanonicalizing([...$tables, 'migrations']);
    foreach (Schema::getTables() as $table) {
        expect($table['engine'])->toBe('InnoDB');
        expect($table['collation'])->toStartWith('utf8mb4');
    }

    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
    expect(Schema::getTableListing(schemaQualified: false))->toBe(['migrations']);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect(Schema::getTableListing(schemaQualified: false))->toEqualCanonicalizing([...$tables, 'migrations']);
});

test('the user factory produces usable users and initialization creates no accounts', function () {
    $this->seed(DatabaseSeeder::class);
    $this->assertDatabaseEmpty('users');
    $user = User::factory()->unverified()->create(['password' => 'synthetic-password']);

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

test('an annual sequence row supports locking and atomic first-row creation', function () {
    $sequence = ExpedientSequence::factory()->create(['year' => 2026, 'last_number' => 17]);
    config(['database.connections.sequence_observer' => config('database.connections.mysql')]);
    $writer = DB::connection('mysql');
    $observer = DB::connection('sequence_observer');
    $observer->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $writer->beginTransaction();

    try {
        $locked = ExpedientSequence::query()->lockForUpdate()->findOrFail($sequence->year);
        expect($locked->last_number)->toBe(17);

        try {
            $observer->table('expedient_sequences')->where('year', 2026)->increment('last_number');
            test()->fail('The second connection unexpectedly updated the locked annual row.');
        } catch (QueryException $exception) {
            expect((int) $exception->errorInfo[1])->toBe(1205);
        }

        $locked->increment('last_number');
        expect($observer->table('expedient_sequences')->where('year', 2026)->value('last_number'))->toBe(17);
        $writer->commit();

        expect($observer->table('expedient_sequences')->where('year', 2026)->value('last_number'))->toBe(18);
        expect($observer->table('expedient_sequences')->insertOrIgnore(['year' => 2026]))->toBe(0);
        $writer->beginTransaction();
        expect($writer->table('expedient_sequences')->insertOrIgnore(['year' => 2027]))->toBe(1);
        expect($observer->table('expedient_sequences')->where('year', 2027)->exists())->toBeFalse();

        try {
            $observer->table('expedient_sequences')->insertOrIgnore(['year' => 2027]);
            test()->fail('The second connection unexpectedly inserted the uncommitted annual row.');
        } catch (QueryException $exception) {
            expect((int) $exception->errorInfo[1])->toBe(1205);
        }

        $writer->commit();
        expect($observer->table('expedient_sequences')->insertOrIgnore(['year' => 2027]))->toBe(0);
        expect($observer->table('expedient_sequences')->where('year', 2027)->value('last_number'))->toBe(0);
    } finally {
        if ($writer->transactionLevel() > 0) {
            $writer->rollBack();
        }

        DB::purge('sequence_observer');
    }
});
