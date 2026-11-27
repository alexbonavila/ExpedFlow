<?php

use App\Models\Expedient;
use App\Models\Status;
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

test('task creation and partial updates preserve authored text and strict civil scheduling', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $case = Expedient::factory()->create();
    $list = "/api/v1/expedients/{$case->id}/tasks";
    $input = ['name' => " \u{2003}Task  A\u{00A0}", 'status_id' => $case->status_id];
    $this->api('POST', $list, [], $headers)->assertUnprocessable()->assertJsonValidationErrors(['name', 'status_id']);
    foreach ([
        ['name' => "\u{00A0}"],
        ['description' => str_repeat('é', 32768)],
        ['status_id' => (string) $case->status_id],
        ['status_id' => 999999],
        ['scheduled_time' => '00:00'],
        ['scheduled_time' => '24:00'],
        ['scheduled_time' => '02:00Z'],
        ['scheduled_date' => ''],
        ['scheduled_date' => '2027-02-29'],
        ['user_ids' => []],
        ['end_date' => null],
    ] as $invalid) {
        $this->api('POST', $list, $invalid + $input, $headers)->assertUnprocessable()
            ->assertJsonValidationErrors(array_key_first($invalid));
    }

    $this->api('POST', $list, ['scheduled_date' => '2028-02-29'] + $input, $headers)->assertCreated()
        ->assertJsonPath('data.position', 1)->assertJsonPath('data.scheduled_time', null);
    $created = $this->api('POST', $list, [
        'scheduled_date' => '2028-02-29', 'scheduled_time' => '00:00', 'description' => "  Preserve  text\n ",
        'position' => 120, 'expedient_id' => 9000, 'deleted_by_expedient' => true,
    ] + $input, $headers)->assertCreated()->assertJsonPath('data.name', 'Task  A')
        ->assertJsonPath('data.position', 2)->assertJsonPath('data.expedient_id', $case->id)
        ->assertJsonPath('data.description', "  Preserve  text\n ")->assertJsonPath('data.scheduled_time', '00:00:00');
    $path = '/api/v1/tasks/'.$created->json('data.id');
    $this->api('PATCH', $path, ['scheduled_date' => null, 'name' => 'Uncommitted'], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('scheduled_time');
    $this->api('PATCH', $path, ['status_id' => $case->status_id], $headers)->assertOk()
        ->assertJsonPath('data.position', 2)->assertJsonPath('data.name', 'Task  A');
    $this->api('PATCH', $path, ['scheduled_date' => null, 'scheduled_time' => null], $headers)->assertOk()
        ->assertJsonPath('data.scheduled_date', null)->assertJsonPath('data.scheduled_time', null);
});

test('PATCH requires reorder only for a status change and appends in the new case column', function (): void {
    $actor = User::factory()->create();
    $role = Role::create(['name' => 'task-editor', 'guard_name' => 'web']);
    $role->givePermissionTo(['expedients.view', 'tasks.view', 'tasks.update']);
    $actor->assignRole($role);
    $headers = $this->authorizationBearer($actor);
    $case = Expedient::factory()->create();
    $task = Task::factory()->for($case)->create(['status_id' => $case->status_id]);
    $destination = Status::factory()->create();
    Task::factory()->for($case)->for($destination)->create(['position' => 7]);
    $path = "/api/v1/tasks/{$task->id}";
    $this->api('PATCH', $path, ['name' => 'Renamed'], $headers)->assertOk();
    $this->api('PATCH', $path, ['status_id' => $destination->id], $headers)->assertForbidden();
    $role->givePermissionTo('tasks.reorder');
    $this->api('PATCH', $path, ['status_id' => $destination->id], $headers)->assertOk()->assertJsonPath('data.position', 8);
    $role->revokePermissionTo('tasks.update');
    $this->api('PATCH', $path, ['name' => 'Rejected'], $headers)->assertForbidden();
    expect($case->refresh()->status_id)->not->toBe($destination->id);
});

test('shared moves insert by visible index and preserve other cases, columns and deleted rows', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole(Role::create(['name' => 'task-orderer', 'guard_name' => 'web'])
        ->givePermissionTo(['expedients.view', 'tasks.view', 'tasks.reorder']));
    $headers = $this->authorizationBearer($actor);
    $case = Expedient::factory()->create();
    $status = Status::factory()->create();
    $a = Task::factory()->for($case)->for($status)->create(['position' => 2]);
    $b = Task::factory()->for($case)->for($status)->create(['position' => 6]);
    $c = Task::factory()->for($case)->for($status)->create(['position' => 8]);
    $deleted = Task::factory()->for($case)->for($status)->create(['position' => 3]);
    $deleted->delete();
    $unrelated = Task::factory()->for($status)->create(['position' => 10]);
    $move = "/api/v1/tasks/{$c->id}/move";
    $input = ['status_id' => $status->id];
    $this->api('POST', $move, ['position' => 3] + $input, $headers)->assertOk()->assertJsonPath('data.position', 8);
    $this->api('POST', $move, ['position' => 4] + $input, $headers)->assertUnprocessable()->assertJsonValidationErrors('position');
    $this->api('POST', $move, ['position' => 1] + $input, $headers)->assertOk()->assertJsonPath('data.position', 1);
    $this->api('POST', "/api/v1/tasks/{$b->id}/move", ['position' => 2] + $input, $headers)
        ->assertOk()->assertJsonPath('data.position', 2);
    $this->api('GET', "/api/v1/expedients/{$case->id}/tasks", headers: $headers)->assertOk()
        ->assertJsonPath('data.0.id', $c->id)->assertJsonPath('data.1.id', $b->id)->assertJsonPath('data.2.id', $a->id);
    $this->api('POST', $move, $input, $headers)->assertOk()->assertJsonPath('data.position', 4);
    $this->api('POST', $move, ['status_id' => $case->status_id], $headers)->assertOk()
        ->assertJsonPath('data.position', 1)->assertJsonPath('data.status_id', $case->status_id);
    expect($deleted->fresh()->position)->toBe(3)->and($unrelated->fresh()->position)->toBe(10)
        ->and($b->fresh()->position)->toBe(2)->and($case->fresh()->status_id)->not->toBe($status->id);
});

test('order exhaustion and a failed second pass leave the entire MySQL column unchanged', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $a = Task::factory()->create(['position' => PHP_INT_MAX - 1]);
    $b = Task::factory()->for($a->expedient)->for($a->status)->create(['position' => 1]);
    $move = "/api/v1/tasks/{$a->id}/move";
    $input = ['status_id' => $a->status_id, 'position' => 1];
    $this->api('POST', $move, $input, $headers)->assertConflict()->assertJsonPath('code', 'task_order_exhausted');
    expect($a->fresh()->position)->toBe(PHP_INT_MAX - 1)->and($b->fresh()->position)->toBe(1);

    $a->forceFill(['position' => 4])->save();
    Task::updating(function (Task $task) use ($b): void {
        if ($task->is($b) && $task->position === 2) {
            throw new RuntimeException('Synthetic order write failure.');
        }
    });
    $this->api('POST', $move, $input, $headers)->assertInternalServerError()->assertJsonPath('code', 'server_error');
    expect($a->fresh()->position)->toBe(4)->and($b->fresh()->position)->toBe(1);
});
