<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestingDatabase;

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

test('the accepted Phase C account data survives a Phase D migration and rollback', function () {
    TestingDatabase::assertSafe($this->app);
    $schema = array_filter(glob('database/migrations/*.php'), fn ($path): bool => ! str_ends_with($path, 'add_account_lifecycle_to_users_table.php'));
    $this->artisan('migrate:fresh', ['--force' => true, '--path' => array_values($schema)])->assertSuccessful();
    expect(Schema::hasColumn('users', 'disabled_at'))->toBeFalse();

    $user = User::factory()->create(['locale' => 'ca']);
    $task = Task::factory()->create();
    $task->assignees()->attach($user);
    $snapshot = $user->refresh()->getRawOriginal();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $user->refresh();
    expect($user->disabled_at)->toBeNull()
        ->and($user->auth_version)->toBe(0)
        ->and(array_intersect_key($user->getRawOriginal(), $snapshot))->toBe($snapshot);
    $this->assertDatabaseHas('task_user', ['task_id' => $task->id, 'user_id' => $user->id]);

    $this->artisan('migrate:rollback', ['--force' => true, '--step' => 1])->assertSuccessful();
    expect(Schema::hasColumn('users', 'disabled_at'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'auth_version'))->toBeFalse()
        ->and($user->refresh()->getRawOriginal())->toBe($snapshot);
    $this->assertDatabaseHas('task_user', ['task_id' => $task->id, 'user_id' => $user->id]);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect($user->refresh()->auth_version)->toBe(0);
});
