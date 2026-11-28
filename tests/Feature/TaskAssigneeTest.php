<?php

use App\Models\Task;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\AuthorizationHttp;
use Tests\Support\RefreshGuardedMySql;

uses(AuthorizationHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('assignment replacement is atomic and retains only disabled assignees already assigned', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $task = Task::factory()->create();
    $a = User::factory()->create();
    $b = User::factory()->create();
    $disabled = User::factory()->create(['disabled_at' => now()]);
    $path = "/api/v1/tasks/{$task->id}/assignees";
    $this->api('GET', $path, headers: $headers)->assertOk()->assertExactJson(['data' => []]);
    $payload = ['user_ids' => [$b->id, $a->id]];
    $expected = ['data' => [['id' => $a->id, 'name' => $a->name, 'active' => true], ['id' => $b->id, 'name' => $b->name, 'active' => true]]];
    $this->api('PUT', $path, $payload, $headers)->assertOk()->assertExactJson($expected);
    $this->api('PUT', $path, $payload, $headers)->assertOk()->assertExactJson($expected);
    foreach ([[$a->id, $a->id], [999999], [$disabled->id], [(string) $b->id], [0], ['key' => $a->id]] as $invalid) {
        $this->api('PUT', $path, ['user_ids' => $invalid], $headers)->assertUnprocessable();
        $this->api('GET', $path, headers: $headers)->assertOk()->assertExactJson($expected);
    }
    $a->forceFill(['disabled_at' => now()])->save();
    $this->api('PUT', $path, ['user_ids' => [$a->id]], $headers)->assertOk()
        ->assertExactJson(['data' => [['id' => $a->id, 'name' => $a->name, 'active' => false]]]);
    $candidates = $this->api('GET', "/api/v1/tasks/{$task->id}/assignee-candidates", headers: $headers)->assertOk();
    $candidates->assertJsonMissingPath('data.0.email')->assertJsonMissingPath('data.0.active');
    expect(array_column($candidates->json('data'), 'id'))->toContain($b->id)->not->toContain($a->id, $disabled->id);
    $this->api('PUT', $path, ['user_ids' => []], $headers)->assertOk()->assertExactJson(['data' => []]);
    $this->api('PUT', $path, ['user_ids' => [$a->id]], $headers)->assertUnprocessable()->assertJsonValidationErrors('user_ids');
    $this->assertDatabaseEmpty('task_user');
});

test('a task reader may see assignment summaries but cannot replace them or list candidates', function (): void {
    $task = Task::factory()->create();
    $role = Role::create(['name' => 'task-reader', 'guard_name' => 'web'])->givePermissionTo(['tasks.view', 'expedients.view']);
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole($role));
    $path = "/api/v1/tasks/{$task->id}";
    $this->api('GET', "$path/assignees", headers: $headers)->assertOk();
    $this->api('GET', "$path/assignee-candidates", headers: $headers)->assertForbidden();
    $this->api('PUT', "$path/assignees", ['user_ids' => []], $headers)->assertForbidden();
    $this->api('PUT', '/api/v1/tasks/999999/assignees', ['user_ids' => []], $headers)->assertForbidden();
});

test('a failed pivot insert rolls back the preceding detach', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $task = Task::factory()->create();
    $original = User::factory()->create();
    $replacement = User::factory()->create();
    $task->assignees()->attach($original->id);
    DB::connection()->beforeExecuting(static function (string $query): void {
        if (str_contains($query, 'insert into `task_user`')) {
            throw new RuntimeException('Synthetic pivot insert failure.');
        }
    });

    $this->api('PUT', "/api/v1/tasks/{$task->id}/assignees", ['user_ids' => [$replacement->id]], $headers)
        ->assertInternalServerError()->assertJsonPath('code', 'server_error');
    expect($task->assignees()->pluck('users.id')->all())->toBe([$original->id]);
});
