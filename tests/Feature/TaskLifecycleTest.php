<?php

use App\Models\Expedient;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Spatie\Permission\Models\Role;
use Tests\Support\AuthorizationHttp;
use Tests\Support\RefreshGuardedMySql;

uses(AuthorizationHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('actual task and case lifecycle routes preserve independent trash and retained descendants', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $headers = $this->authorizationBearer($actor);
    $admin = $this->authorizationBearer($this->authorizationAdmin());
    $case = Expedient::factory()->create();
    $other = Expedient::factory()->create();
    $casePath = "/api/v1/expedients/{$case->id}";
    $root = "$casePath/tasks";
    $create = fn (string $name) => $this->api('POST', $root, ['name' => $name, 'status_id' => $case->status_id], $headers)
        ->assertCreated();
    $first = $create('Independent')->assertJsonPath('data.position', 1)->json('data.id');
    $path = "/api/v1/tasks/$first";
    $this->api('PUT', "$path/assignees", ['user_ids' => [$actor->id]], $headers)->assertOk();
    $activeComment = $this->api('POST', "$path/comments", ['body' => 'Keep visible'], $headers)->assertCreated()->json('data.id');
    $trashComment = $this->api('POST', "$path/comments", ['body' => 'Keep trashed'], $headers)->assertCreated()->json('data.id');
    $this->api('DELETE', "$path/comments/$trashComment", headers: $headers)->assertOk();
    $item = $this->api('POST', "$path/checklist", ['name' => 'Retain item', 'completed' => true], $headers)->assertCreated()->json('data.id');
    $this->api('POST', "$path/force-delete", headers: $admin)->assertNotFound();
    $this->api('DELETE', $path, headers: $headers)->assertOk()->assertJsonPath('code', 'task_deleted');
    $this->api('GET', "$path/comments", headers: $headers)->assertNotFound();
    $this->api('GET', "$path/checklist", headers: $headers)->assertNotFound();
    $this->api('POST', "$path/comments/$trashComment/restore", headers: $headers)->assertNotFound();
    $this->api('POST', "$path/comments/$trashComment/force-delete", headers: $admin)->assertNotFound();
    $this->api('GET', "$path/assignees", headers: $headers)->assertNotFound();
    $this->api('GET', "$root/trash", headers: $headers)->assertOk()->assertJsonCount(1, 'data');
    $this->api('GET', "$root/trash/$first", headers: $headers)->assertOk()
        ->assertJsonStructure(['data' => ['deleted_at']])->assertJsonMissingPath('data.deleted_by_expedient');
    $this->api('GET', "/api/v1/expedients/{$other->id}/tasks/trash/$first", headers: $headers)->assertNotFound();
    $this->api('POST', "$path/force-delete", headers: $headers)->assertForbidden();

    $second = $create('Cascaded')->assertJsonPath('data.position', 1)->json('data.id');
    $secondPath = "/api/v1/tasks/$second";
    $this->api('POST', "$secondPath/comments", ['body' => 'Survives case cycles'], $headers)->assertCreated();
    $this->api('POST', "$secondPath/checklist", ['name' => 'Survives too'], $headers)->assertCreated();
    for ($cycle = 0; $cycle < 2; $cycle++) {
        $this->api('DELETE', $casePath, headers: $headers)->assertOk();
        $this->api('GET', "$root/trash", headers: $headers)->assertNotFound();
        $this->api('POST', "$path/restore", headers: $headers)->assertNotFound();
        $this->api('POST', "$path/force-delete", headers: $admin)->assertNotFound();
        $this->api('POST', "$secondPath/restore", headers: $headers)->assertNotFound();
        $this->api('POST', "$secondPath/force-delete", headers: $admin)->assertNotFound();
        $this->api('GET', "$secondPath/comments", headers: $headers)->assertNotFound();
        $this->api('POST', "$casePath/restore", headers: $headers)->assertOk();
        $this->api('GET', $secondPath, headers: $headers)->assertOk()->assertJsonPath('data.position', 1);
        $this->api('GET', "$secondPath/comments", headers: $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', "$secondPath/checklist", headers: $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', $path, headers: $headers)->assertNotFound();
    }

    $this->api('POST', "$path/restore", headers: $headers)->assertOk()->assertJsonPath('data.position', 2)
        ->assertJsonPath('data.assignees.0.id', $actor->id)->assertJsonMissingPath('data.deleted_at');
    $this->api('GET', "$path/comments/$activeComment", headers: $headers)->assertOk();
    $this->api('GET', "$path/comments/trash/$trashComment", headers: $headers)->assertOk();
    $this->api('GET', "$path/checklist/$item", headers: $headers)->assertOk()->assertJsonPath('data.completed', true);
    $this->api('DELETE', $path, headers: $headers)->assertOk();
    $this->api('POST', "$path/restore", headers: $headers)->assertOk()->assertJsonPath('data.position', 2);
    $this->api('DELETE', $path, headers: $headers)->assertOk();
    $this->api('POST', "$path/force-delete", headers: $admin)->assertOk()->assertJsonPath('code', 'task_force_deleted');
    foreach (['task_user', 'task_comments', 'task_checklist_items'] as $table) {
        $this->assertDatabaseMissing($table, ['task_id' => $first]);
    }
    $this->assertDatabaseMissing('tasks', ['id' => $first]);
    $this->api('DELETE', $casePath, headers: $headers)->assertOk();
    $this->api('POST', "$casePath/force-delete", headers: $admin)->assertOk();
    $this->assertDatabaseMissing('tasks', ['id' => $second]);
    $this->assertDatabaseMissing('task_comments', ['task_id' => $second]);
    $this->assertDatabaseMissing('task_checklist_items', ['task_id' => $second]);
});

test('task trash grants hide scope and fail atomically when persistence is interrupted', function (): void {
    $task = Task::factory()->create();
    $path = "/api/v1/tasks/{$task->id}";
    $trash = "/api/v1/expedients/{$task->expedient_id}/tasks/trash";
    $role = Role::create(['name' => 'trash-reader', 'guard_name' => 'web'])->givePermissionTo(['tasks.view', 'expedients.view']);
    $reader = $this->authorizationBearer(User::factory()->create()->assignRole($role));
    $writer = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $this->api('GET', $trash, headers: $reader)->assertForbidden();
    $this->api('GET', '/api/v1/expedients/999999/tasks/trash', headers: $reader)->assertForbidden();
    $this->api('POST', "$path/restore", headers: $reader)->assertForbidden();
    $this->api('DELETE', $path, headers: $writer)->assertOk();
    Task::restored(function (): never {
        throw new RuntimeException('Synthetic restore failure.');
    });
    $this->api('POST', "$path/restore", headers: $writer)->assertInternalServerError();
    expect($task->fresh()->trashed())->toBeTrue();
});
