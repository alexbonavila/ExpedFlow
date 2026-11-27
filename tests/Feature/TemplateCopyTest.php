<?php

use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Status;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Spatie\Permission\Models\Role;
use Tests\Support\CaseHttp;
use Tests\Support\RefreshGuardedMySql;

uses(CaseHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('case creation copies independent ordered tasks and checklist items only when requested', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $type = ExpedientType::factory()->create(['code' => '07']);
    $late = TaskTemplate::factory()->create(['expedient_type_id' => $type->id, 'name' => 'Late', 'position' => 9]);
    $early = TaskTemplate::factory()->create([
        'expedient_type_id' => $type->id, 'name' => 'Early', 'position' => 3, 'description' => 'Independent text',
    ]);
    TaskTemplate::factory()->create(['expedient_type_id' => ExpedientType::factory()->create(['code' => '08'])->id]);
    foreach ([['Late item', 7], ['Early item', 1], ['Tie', 1]] as [$name, $position]) {
        TaskTemplateChecklistItem::factory()->create(['task_template_id' => $early->id, 'position' => $position, 'name' => $name]);
    }
    $input = $this->caseInput(['expedient_type_id' => $type->id]);
    $status = Status::factory()->create();
    $this->api('POST', '/api/v1/expedients', $input + ['copy_template' => false], $headers)->assertCreated();
    $this->assertDatabaseEmpty('tasks');

    $id = $this->api('POST', '/api/v1/expedients', $input + ['copy_template' => true, 'template_status_id' => $status->id], $headers)
        ->assertCreated()->assertJsonPath('data.status_id', $input['status_id'])
        ->assertJsonMissingPath('data.tasks')->json('data.id');
    $case = Expedient::query()->findOrFail($id);
    expect($case->sequence_number)->toBe(2);
    $tasks = $case->tasks()->orderBy('position')->get();
    expect($tasks->pluck('name')->all())->toBe(['Early', 'Late'])
        ->and($tasks->pluck('position')->all())->toBe([1, 2])
        ->and($tasks->pluck('status_id')->all())->toBe([$status->id, $status->id]);
    $task = $tasks->first();
    expect($task->description)->toBe('Independent text')
        ->and($task->scheduled_date)->toBeNull()
        ->and($task->scheduled_time)->toBeNull()
        ->and($task->assignees()->exists())->toBeFalse();
    $checklist = $task->checklistItems()->orderBy('position')->get();
    expect($checklist->pluck('name')->all())->toBe(['Early item', 'Tie', 'Late item'])
        ->and($checklist->pluck('position')->all())->toBe([1, 2, 3])
        ->and($checklist->pluck('completed')->all())->toBe([false, false, false]);

    $early->forceFill(['name' => 'Changed source'])->save();
    $early->delete();
    $late->delete();
    $newType = ExpedientType::factory()->create(['code' => '09']);
    $this->api('PATCH', "/api/v1/expedients/$id", ['expedient_type_id' => $newType->id], $headers)->assertOk();
    $this->api('DELETE', "/api/v1/expedients/$id", headers: $headers)->assertOk();
    $this->api('POST', "/api/v1/expedients/$id/restore", headers: $headers)->assertOk();
    expect($case->tasks()->orderBy('position')->pluck('name')->all())->toBe(['Early', 'Late']);
    $this->assertDatabaseCount('task_checklist_items', 3);
});

test('strict template input rejects implicit, unknown or PATCH template copying', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $input = $this->caseInput();
    foreach ([
        [['copy_template' => 1], 'copy_template'],
        [['copy_template' => null], 'copy_template'],
        [['copy_template' => true], 'template_status_id'],
        [['copy_template' => true, 'template_status_id' => (string) $input['status_id']], 'template_status_id'],
        [['copy_template' => true, 'template_status_id' => 999999], 'template_status_id'],
        [['copy_template' => false, 'template_status_id' => $input['status_id']], 'template_status_id'],
        [['template_status_id' => null], 'template_status_id'],
    ] as [$extra, $field]) {
        $this->api('POST', '/api/v1/expedients', $input + $extra, $headers)->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->assertDatabaseEmpty('expedients');
    $this->assertDatabaseEmpty('expedient_sequences');
    $case = $this->api('POST', '/api/v1/expedients', $input + ['copy_template' => true, 'template_status_id' => $input['status_id']], $headers)
        ->assertCreated()->json('data.id');
    $this->api('PATCH', "/api/v1/expedients/$case", ['copy_template' => false], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('copy_template');
    $this->assertDatabaseEmpty('tasks');
    expect(Expedient::query()->sole()->sequence_number)->toBe(1);
});

test('composite grants and persistence failures roll back the case, annual counter and copy', function (): void {
    $role = Role::create(['name' => 'Template consumer', 'guard_name' => 'web']);
    $role->givePermissionTo('expedients.create', 'expedients.view', 'tasks.create', 'tasks.view', 'expedient-types.view');
    $actor = User::factory()->create()->assignRole($role);
    $headers = $this->authorizationBearer($actor);
    $input = $this->caseInput();
    $blueprint = TaskTemplate::factory()->create(['expedient_type_id' => $input['expedient_type_id']]);
    TaskTemplateChecklistItem::factory()->create(['task_template_id' => $blueprint->id]);
    $input += ['copy_template' => true, 'template_status_id' => $input['status_id']];
    $path = '/api/v1/expedients';

    $this->api('POST', $path, $input, $headers)->assertForbidden();
    $role->givePermissionTo('task-templates.view');
    $this->api('POST', $path, $input, $headers)->assertForbidden();
    $role->givePermissionTo('task-checklists.manage');
    TaskChecklistItem::creating(fn (): bool => false);
    $this->api('POST', $path, $input, $headers)->assertInternalServerError()->assertJsonPath('code', 'server_error');

    foreach (['expedients', 'expedient_sequences', 'tasks', 'task_checklist_items'] as $table) {
        $this->assertDatabaseEmpty($table);
    }
    TaskChecklistItem::flushEventListeners();
    $this->api('POST', $path, $input, $headers)->assertCreated();
    expect(Task::query()->sole()->checklistItems()->count())->toBe(1);
    expect(Expedient::query()->sole()->sequence_number)->toBe(1);
});
