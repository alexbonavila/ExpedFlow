<?php

use App\Models\Task;
use App\Models\TaskChecklistItem;
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

test('checklist items validate, append, complete, reorder and delete independently', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $task = Task::factory()->create();
    $foreign = TaskChecklistItem::factory()->create();
    $root = "/api/v1/tasks/{$task->id}/checklist";
    $this->api('PUT', "$root/order", ['item_ids' => []], $headers)->assertOk()->assertExactJson(['data' => []]);
    $this->api('POST', $root, ['name' => "\u{2003}", 'completed' => 1], $headers)->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'completed']);
    $this->api('POST', $root, ['name' => str_repeat('x', 256)], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');
    $a = $this->api('POST', $root, ['name' => '  First  item  ', 'position' => 40, 'task_id' => $foreign->task_id], $headers)
        ->assertCreated()->assertJsonPath('data.name', 'First  item')->assertJsonPath('data.completed', false)
        ->assertJsonPath('data.position', 1)->assertJsonPath('data.task_id', $task->id)->json('data.id');
    $b = $this->api('POST', $root, ['name' => 'Second', 'completed' => true], $headers)->assertCreated()
        ->assertJsonPath('data.position', 2)->assertJsonPath('data.completed', true)->json('data.id');

    $this->api('PATCH', "$root/$a", ['completed' => 'true'], $headers)->assertUnprocessable()->assertJsonValidationErrors('completed');
    $this->api('PATCH', "$root/$a", ['completed' => true], $headers)->assertOk()->assertJsonPath('data.completed', true);
    $this->api('PATCH', "$root/$b", ['completed' => false], $headers)->assertOk()->assertJsonPath('data.completed', false);
    $this->api('GET', "$root/{$foreign->id}", headers: $headers)->assertNotFound();
    $this->api('GET', "$root/$a", headers: $headers)->assertOk()->assertJsonPath('data.name', 'First  item');
    $this->api('PUT', "$root/order", ['item_ids' => [$a, $a]], $headers)->assertUnprocessable();
    foreach ([[$b], [$a, $foreign->id], []] as $invalid) {
        $this->api('PUT', "$root/order", ['item_ids' => $invalid], $headers)->assertConflict()
            ->assertJsonPath('code', 'task_checklist_membership_changed');
    }
    $this->api('PUT', "$root/order", ['item_ids' => [$b, $a]], $headers)->assertOk()
        ->assertJsonPath('data.0.id', $b)->assertJsonPath('data.1.id', $a);
    $this->api('GET', $root, headers: $headers)->assertOk()->assertJsonPath('data.0.id', $b);
    $this->api('DELETE', "$root/$b", headers: $headers)->assertOk()->assertJsonPath('code', 'task_checklist_deleted');
    $this->api('GET', "$root/$b", headers: $headers)->assertNotFound();
    $this->assertDatabaseMissing('task_checklist_items', ['id' => $b]);
    expect($task->fresh()->status_id)->toBe($task->status_id)->and($foreign->fresh()->position)->toBe($foreign->position);
});

test('checklist management requires its grant and a failed reorder rolls back prior item writes', function (): void {
    $task = Task::factory()->create();
    $a = TaskChecklistItem::factory()->for($task)->create(['position' => 1]);
    $b = TaskChecklistItem::factory()->for($task)->create(['position' => 2]);
    $root = "/api/v1/tasks/{$task->id}/checklist";
    $role = Role::create(['name' => 'checklist-reader', 'guard_name' => 'web'])->givePermissionTo(['tasks.view', 'expedients.view']);
    $reader = $this->authorizationBearer(User::factory()->create()->assignRole($role));
    $writer = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $this->api('GET', $root, headers: $reader)->assertOk();
    $this->api('DELETE', "$root/{$a->id}", headers: $reader)->assertForbidden();
    $this->api('POST', '/api/v1/tasks/999999/checklist', ['name' => 'Denied'], $reader)->assertForbidden();

    TaskChecklistItem::updated(function (TaskChecklistItem $item) use ($a): void {
        if ($item->is($a)) {
            throw new RuntimeException('Synthetic second item write failure.');
        }
    });
    $this->api('PUT', "$root/order", ['item_ids' => [$b->id, $a->id]], $writer)
        ->assertInternalServerError()->assertJsonPath('code', 'server_error');
    expect($a->fresh()->position)->toBe(1)->and($b->fresh()->position)->toBe(2);

    $case = "/api/v1/expedients/{$task->expedient_id}";
    $this->api('DELETE', $case, headers: $writer)->assertOk();
    $this->api('GET', $root, headers: $writer)->assertNotFound();
    $this->api('POST', "$case/restore", headers: $writer)->assertOk();
    $this->api('GET', $root, headers: $writer)->assertOk()->assertJsonCount(2, 'data');
});
