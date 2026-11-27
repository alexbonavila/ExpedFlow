<?php

use App\Models\Expedient;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Tests\Support\AuthorizationHttp;
use Tests\Support\RefreshGuardedMySql;

uses(AuthorizationHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('task reads require authenticated case and task grants before disclosing active records', function (): void {
    $task = Task::factory()->create();
    $path = "/api/v1/tasks/{$task->id}";
    $list = "/api/v1/expedients/{$task->expedient_id}/tasks";
    $this->api('GET', $path)->assertUnauthorized();
    $roleless = $this->authorizationBearer(User::factory()->create());
    $this->api('GET', $path, headers: $roleless)->assertForbidden();
    $this->api('GET', '/api/v1/tasks/999999', headers: $roleless)->assertForbidden();
    $reader = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $this->api('GET', $path, headers: $reader)->assertOk()->assertJsonPath('data.id', $task->id)
        ->assertJsonPath('data.assignees', [])->assertJsonMissingPath('data.active_position')
        ->assertJsonMissingPath('data.deleted_by_expedient')->assertHeader('Cache-Control', 'no-store, private');
    $this->api('GET', $list, headers: $reader)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('links');
    $this->api('GET', '/api/v1/tasks/0', headers: $reader)->assertNotFound();
    $this->api('GET', '/api/v1/tasks/invalid', headers: $reader)->assertNotFound();

    Expedient::query()->findOrFail($task->expedient_id)->delete();
    $this->api('GET', $path, headers: $reader)->assertNotFound();
    $this->api('GET', $list, headers: $reader)->assertNotFound();
});
