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
use Tests\Support\CaseHttp;
use Tests\Support\RefreshGuardedMySql;

uses(CaseHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('template copies use the live task APIs and keep independent state across case trash', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $headers = $this->authorizationBearer($actor);
    $admin = $this->authorizationBearer($this->authorizationAdmin());
    $caseStatus = Status::factory()->create();
    $statuses = '/api/v1/statuses';
    $initial = $this->api('POST', $statuses, ['name' => 'Copy column'], $admin)->assertCreated()->json('data.id');
    $destination = $this->api('POST', $statuses, ['name' => 'Destination'], $admin)->assertCreated()->json('data.id');
    $this->api('PATCH', "$statuses/$initial", ['name' => 'Renamed copy column'], $admin)->assertOk();
    $type = ExpedientType::factory()->create();
    $templates = "/api/v1/expedient-types/{$type->id}/task-templates";
    $source = $this->api('POST', $templates, ['name' => 'First copy', 'description' => 'Original'], $headers)
        ->assertCreated()->json('data.id');
    $this->api('POST', $templates, ['name' => 'Second copy'], $headers)->assertCreated();
    $blueprint = $this->api('POST', "$templates/$source/checklist", ['name' => 'Original item'], $headers)
        ->assertCreated()->json('data.id');
    $this->api('POST', "$templates/$source/checklist", ['name' => 'Second item'], $headers)->assertCreated();
    $input = $this->caseInput(['expedient_type_id' => $type->id, 'status_id' => $caseStatus->id]);
    $id = $this->api('POST', '/api/v1/expedients', $input + ['copy_template' => true, 'template_status_id' => $initial], $headers)
        ->assertCreated()->assertJsonPath('data.status_id', $caseStatus->id)->json('data.id');
    $case = "/api/v1/expedients/$id";
    $copies = $this->api('GET', "$case/tasks", headers: $headers)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'First copy')->assertJsonPath('data.0.description', 'Original')
        ->assertJsonPath('data.0.status.name', 'Renamed copy column')->assertJsonPath('data.0.status_id', $initial)
        ->assertJsonPath('data.0.position', 1)->assertJsonPath('data.0.assignees', [])
        ->assertJsonPath('data.0.scheduled_date', null)->assertJsonPath('data.1.name', 'Second copy')->assertJsonPath('data.1.position', 2);
    $first = $copies->json('data.0.id');
    $second = $copies->json('data.1.id');
    $task = "/api/v1/tasks/$first";
    $secondTask = "/api/v1/tasks/$second";
    $item = $this->api('GET', "$task/checklist", headers: $headers)->assertOk()
        ->assertJsonPath('data.0.name', 'Original item')->assertJsonPath('data.0.completed', false)
        ->assertJsonPath('data.0.position', 1)->assertJsonPath('data.1.name', 'Second item')
        ->assertJsonPath('data.1.position', 2)->json('data.0.id');

    $this->api('PATCH', "$templates/$source", ['name' => 'Updated blueprint'], $headers)->assertOk();
    $this->api('PATCH', "$templates/$source/checklist/$blueprint", ['name' => 'Updated blueprint item'], $headers)->assertOk();
    $this->api('GET', $task, headers: $headers)->assertOk()->assertJsonPath('data.name', 'First copy');
    $this->api('GET', "$task/checklist/$item", headers: $headers)->assertOk()->assertJsonPath('data.name', 'Original item');
    $this->api('PATCH', "$task/checklist/$item", ['completed' => true, 'name' => 'Completed copy'], $headers)->assertOk();
    $this->api('GET', "$templates/$source/checklist/$blueprint", headers: $headers)->assertOk()
        ->assertJsonPath('data.name', 'Updated blueprint item');
    $this->api('PATCH', $task, ['name' => 'Working task', 'scheduled_date' => '2028-02-29', 'scheduled_time' => '09:30'], $headers)
        ->assertOk()->assertJsonPath('data.scheduled_time', '09:30:00');
    $this->api('PUT', "$task/assignees", ['user_ids' => [$actor->id]], $headers)->assertOk();
    $comment = $this->api('POST', "$task/comments", ['body' => 'Copied task note'], $headers)->assertCreated()->json('data.id');
    $calendar = '/api/v1/calendar/tasks?from=2028-02-29&to=2028-02-29';
    $this->api('GET', $calendar, headers: $headers)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $first)->assertJsonPath('data.0.all_day', false);

    $manual = $this->api('POST', "$case/tasks", ['name' => 'Manual task', 'status_id' => $initial], $headers)
        ->assertCreated()->assertJsonPath('data.position', 3)->json('data.id');
    $manualTask = "/api/v1/tasks/$manual";
    $this->api('POST', "$secondTask/move", ['status_id' => $destination], $headers)->assertOk()->assertJsonPath('data.position', 1);
    $this->api('POST', "$manualTask/move", ['status_id' => $destination, 'position' => 1], $headers)->assertOk()->assertJsonPath('data.position', 1);
    $this->api('PUT', "$statuses/order", ['status_ids' => [$destination, $initial, $caseStatus->id]], $admin)->assertOk();
    $this->api('GET', "$case/tasks", headers: $headers)->assertOk()->assertJsonPath('data.0.id', $manual)
        ->assertJsonPath('data.1.id', $second)->assertJsonPath('data.1.position', 2)->assertJsonPath('data.2.id', $first);
    $this->api('POST', "$manualTask/move", ['status_id' => $initial], $headers)->assertOk();
    foreach ([$initial, $destination, $caseStatus->id] as $status) {
        $this->api('DELETE', "$statuses/$status", headers: $admin)->assertConflict()->assertJsonPath('code', 'status_referenced');
    }
    $this->api('DELETE', $secondTask, headers: $headers)->assertOk();
    $this->api('DELETE', "$statuses/$destination", headers: $admin)->assertConflict()->assertJsonPath('code', 'status_referenced');
    $this->api('DELETE', $case, headers: $headers)->assertOk();
    $this->assertSoftDeleted('tasks', ['id' => $first, 'deleted_by_expedient' => true]);
    $this->assertSoftDeleted('tasks', ['id' => $manual, 'deleted_by_expedient' => true]);
    $this->assertSoftDeleted('tasks', ['id' => $second, 'deleted_by_expedient' => false]);
    $this->api('DELETE', "$statuses/{$caseStatus->id}", headers: $admin)->assertConflict()->assertJsonPath('code', 'status_referenced');
    $this->api('GET', $calendar, headers: $headers)->assertOk()->assertJsonCount(0, 'data');
    $this->api('GET', "$task/checklist", headers: $headers)->assertNotFound();
    $this->api('POST', "$case/restore", headers: $headers)->assertOk();
    $this->api('GET', "$case/tasks", headers: $headers)->assertOk()->assertJsonCount(2, 'data');
    $this->api('GET', $secondTask, headers: $headers)->assertNotFound();
    $this->api('GET', "$task/comments/$comment", headers: $headers)->assertOk()->assertJsonPath('data.body', 'Copied task note');
    $this->api('GET', "$task/checklist/$item", headers: $headers)->assertOk()->assertJsonPath('data.completed', true);
    $this->api('GET', "$task/assignees", headers: $headers)->assertOk()->assertJsonPath('data.0.id', $actor->id);
    $this->api('GET', $calendar, headers: $headers)->assertOk()->assertJsonPath('data.0.id', $first);
    $this->api('POST', "$secondTask/restore", headers: $headers)->assertOk()->assertJsonPath('data.position', 2);
    $this->api('DELETE', $case, headers: $headers)->assertOk();
    $this->api('POST', "$case/force-delete", headers: $admin)->assertOk();
    foreach (['tasks', 'task_user', 'task_comments', 'task_checklist_items'] as $table) {
        $this->assertDatabaseEmpty($table);
    }
    $this->api('GET', "$templates/$source", headers: $headers)->assertOk()->assertJsonPath('data.name', 'Updated blueprint');
    $this->api('DELETE', "$statuses/$destination", headers: $admin)->assertOk()->assertJsonPath('code', 'status_deleted');
});

test('a late checklist-copy failure rolls back earlier copied rows and the case number', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create(['locale' => 'en'])->assignRole('user'));
    $input = $this->caseInput();
    foreach ([1 => 'Persists first', 2 => 'Stops creation'] as $position => $name) {
        $template = TaskTemplate::factory()->create(['expedient_type_id' => $input['expedient_type_id'], 'position' => $position]);
        TaskTemplateChecklistItem::factory()->create(['task_template_id' => $template->id, 'name' => $name]);
    }
    $writeCount = 0;
    TaskChecklistItem::created(function () use (&$writeCount): void {
        $writeCount++;
    });
    TaskChecklistItem::creating(fn (TaskChecklistItem $item): bool => $item->name !== 'Stops creation');
    $input += ['copy_template' => true, 'template_status_id' => $input['status_id']];
    $path = '/api/v1/expedients';
    $this->api('POST', $path, $input, $headers)->assertInternalServerError()
        ->assertExactJson(['code' => 'server_error', 'message' => 'An unexpected error occurred.']);
    expect($writeCount)->toBe(1);
    foreach (['expedients', 'expedient_sequences', 'tasks', 'task_checklist_items'] as $table) {
        $this->assertDatabaseEmpty($table);
    }
    TaskChecklistItem::flushEventListeners();
    $id = $this->api('POST', $path, $input, $headers)->assertCreated()->json('data.id');
    expect(Expedient::query()->findOrFail($id)->sequence_number)->toBe(1);
    $copies = $this->api('GET', "$path/$id/tasks", headers: $headers)->assertOk()->assertJsonCount(2, 'data');
    $first = $copies->json('data.0.id');
    $second = $copies->json('data.1.id');
    $this->api('GET', "/api/v1/tasks/$first/checklist", headers: $headers)->assertOk()->assertJsonPath('data.0.name', 'Persists first');
    $this->api('GET', "/api/v1/tasks/$second/checklist", headers: $headers)->assertOk()->assertJsonPath('data.0.name', 'Stops creation');
});

test('private case visibility and ordering coexist with shared task order and the actor calendar', function (): void {
    $owner = User::factory()->create()->assignRole('user');
    $other = User::factory()->create()->assignRole('user');
    $a = $this->authorizationBearer($owner);
    $b = $this->authorizationBearer($other);
    $source = Status::factory()->create(['position' => 1]);
    $destination = Status::factory()->create(['position' => 2]);
    $moving = Expedient::factory()->create(['status_id' => $source->id, 'column_position' => 1]);
    $peer = Expedient::factory()->create(['status_id' => $source->id, 'column_position' => 2]);
    $first = Task::factory()->create(['expedient_id' => $moving->id, 'status_id' => $source->id, 'position' => 1, 'scheduled_date' => '2028-02-29']);
    $second = Task::factory()->create(['expedient_id' => $moving->id, 'status_id' => $source->id, 'position' => 2]);
    $path = "/api/v1/expedients/{$moving->id}";
    $board = '/api/v1/expedients/board';
    $task = "/api/v1/tasks/{$first->id}";
    $this->api('PUT', "$task/assignees", ['user_ids' => [$owner->id, $other->id]], $a)->assertOk();
    $this->api('PATCH', "$path/preferences", ['hidden' => true], $a)->assertOk();
    $this->api('GET', "$path/preferences", headers: $b)->assertOk()->assertJsonPath('data.hidden', false);
    $this->api('POST', "$path/move", ['status_id' => $source->id], $a)->assertOk()->assertJsonPath('data.position', 2);
    $this->api('GET', $board, headers: $a)->assertOk()->assertJsonPath('data.0.expedients.0.id', $peer->id)
        ->assertJsonPath('data.0.expedients.1.id', $moving->id)->assertJsonPath('data.0.expedients.1.hidden', true);
    $this->api('GET', $board, headers: $b)->assertOk()->assertJsonPath('data.0.expedients.0.id', $moving->id);
    $this->api('POST', "$task/move", ['status_id' => $source->id, 'position' => 2], $b)->assertOk();
    $this->api('GET', "$path/preferences", headers: $a)->assertOk()->assertJsonPath('data.position', 2);
    $this->api('POST', "$path/move", ['status_id' => $destination->id], $a)->assertOk();
    $this->api('GET', $board, headers: $b)->assertOk()->assertJsonPath('data.1.expedients.0.id', $moving->id);
    $this->api('GET', "$path/preferences", headers: $a)->assertOk()->assertJsonPath('data.hidden', true);
    foreach ([$a, $b] as $headers) {
        $this->api('GET', "$path/tasks", headers: $headers)->assertOk()
            ->assertJsonPath('data.0.id', $second->id)->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.1.status_id', $source->id);
        $this->api('GET', '/api/v1/calendar/tasks', headers: $headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
    }
});

test('shared error renderer selects task, catalog and default messages in each locale', function (
    string $locale, string $taskMessage, string $catalogMessage, string $validationMessage, string $dateMessage
): void {
    $admin = $this->authorizationBearer($this->authorizationAdmin($locale));
    $task = Task::factory()->create();
    TaskChecklistItem::factory()->create(['task_id' => $task->id]);
    $case = "/api/v1/expedients/{$task->expedient_id}";
    $this->api('PUT', "/api/v1/tasks/{$task->id}/checklist/order", ['item_ids' => []], $admin)
        ->assertConflict()->assertExactJson(['code' => 'task_checklist_membership_changed', 'message' => $taskMessage])
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->api('DELETE', "/api/v1/statuses/{$task->status_id}", headers: $admin)
        ->assertConflict()->assertExactJson(['code' => 'status_referenced', 'message' => $catalogMessage])
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->api('POST', "$case/tasks", ['name' => 'Invalid date', 'status_id' => $task->status_id, 'scheduled_date' => '2027-02-29'], $admin)
        ->assertUnprocessable()->assertExactJson([
            'code' => 'validation_failed', 'message' => $validationMessage, 'errors' => ['scheduled_date' => [$dateMessage]],
        ])->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'English' => ['en', 'The checklist has changed. Send all current item IDs.', 'This status is referenced by a case or task.',
        'Check the highlighted fields.', 'Enter a valid date in YYYY-MM-DD format.'],
    'Spanish' => ['es', 'La lista de verificación ha cambiado. Envía todos los identificadores actuales.',
        'Un expediente o una tarea hace referencia a este estado.', 'Revisa los campos indicados.', 'Introduce una fecha válida en formato AAAA-MM-DD.'],
    'Catalan' => ['ca', 'La llista de verificació ha canviat. Envia tots els identificadors actuals.',
        'Un expedient o una tasca fa referència a aquest estat.', 'Revisa els camps indicats.', 'Introdueix una data vàlida en format AAAA-MM-DD.'],
]);
