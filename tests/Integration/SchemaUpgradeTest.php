<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestingDatabase;

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

test('Phase B accounts and sessions survive upgrading and rolling Phase C back', function () {
    TestingDatabase::assertSafe($this->app);
    $this->artisan('migrate:fresh', [
        '--force' => true,
        '--path' => [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/0001_01_01_000001_create_cache_table.php',
            'database/migrations/0001_01_01_000002_create_jobs_table.php',
        ],
    ])->assertSuccessful();
    expect(Schema::hasColumn('users', 'locale'))->toBeFalse();

    $id = DB::table('users')->insertGetId([
        'email' => 'baseline@example.test',
        'name' => 'Existing account',
        'password' => Hash::make('synthetic-password'),
        'email_verified_at' => '2026-01-01 11:00:00',
    ]);
    DB::table('sessions')->insert([
        'id' => 'baseline-session',
        'user_id' => $id,
        'payload' => 'synthetic-payload',
        'last_activity' => 1_767_264_000,
    ]);
    $originalUser = (array) DB::table('users')->where('id', $id)->first();
    $originalSession = DB::table('sessions')->where('id', 'baseline-session')->first();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $user = User::query()->findOrFail($id);
    expect($user->locale)->toBe('es');
    expect($user->email_verified_at->toISOString())->toBe('2026-01-01T10:00:00.000000Z');
    expect((array) DB::table('users')->where('id', $id)->first(array_keys($originalUser)))->toBe($originalUser);
    expect(DB::table('sessions')->where('id', 'baseline-session')->first())->toEqual($originalSession);

    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
    expect(Schema::hasColumn('users', 'locale'))->toBeFalse();
    expect(Schema::hasTable('expedients'))->toBeFalse();
    expect((array) DB::table('users')->where('id', $id)->first())->toBe($originalUser);
    expect(DB::table('sessions')->where('id', 'baseline-session')->first())->toEqual($originalSession);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect(User::query()->findOrFail($id)->locale)->toBe('es');
});
