<?php

use App\Models\Expedient;
use App\Models\ExpedientSequence;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CaseHttp;
use Tests\Support\RefreshGuardedMySql;

uses(CaseHttp::class, RefreshGuardedMySql::class);

beforeEach(function () {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('case and selected template copies retain one operation ID without exposing descriptions or storage intent values', function () {
    $actor = User::factory()->create()->assignRole('user');
    $headers = $this->authorizationBearer($actor);
    $input = $this->caseInput(['description' => 'private-case-description']);
    $template = TaskTemplate::factory()->create(['expedient_type_id' => $input['expedient_type_id'], 'description' => 'private-template-description']);
    $item = TaskTemplateChecklistItem::factory()->create(['task_template_id' => $template->id]);
    $created = $this->api('POST', '/api/v1/expedients', $input + [
        'copy_template' => true, 'template_status_id' => $input['status_id'],
    ], $headers)->assertCreated();
    $case = Expedient::query()->findOrFail($created->json('data.id'));

    $rows = Activity::query()->orderBy('id')->get();
    expect($rows->pluck('event')->all())->toBe(['task.copied', 'checklist.copied', 'case.templates_copied', 'case.created']);
    expect($rows->pluck('causer_id')->unique()->values()->all())->toBe([$actor->id]);
    expect($rows[0]->subject_id)->toBe($case->tasks()->sole()->id);
    expect($rows[1]->getProperty('metadata.source_item_id'))->toBe($item->id);
    expect($rows[3]->getProperty('case_code'))->toBe($case->code);
    expect($rows[3]->getProperty('metadata.storage_reserved'))->toBeTrue();
    expect($rows->map(fn (Activity $row) => $row->getProperty('operation_id'))->unique())->toHaveCount(1);
    expect(json_encode(DB::table('activity_log')->get()))->not->toContain('private-case-description', 'private-template-description', $case->samba_relative_path);

    $this->api('PATCH', "/api/v1/expedients/{$case->id}", ['name' => $case->name], $headers)->assertOk();
    $this->api('GET', "/api/v1/expedients/{$case->id}", headers: $headers)->assertOk();
    expect(Activity::query()->count())->toBe(4);
});

test('rejected case audit rolls back case numbering and copied records before storage can begin', function () {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $input = $this->caseInput();
    TaskTemplate::factory()->create(['expedient_type_id' => $input['expedient_type_id']]);
    Activity::creating(fn (Activity $entry): bool => $entry->event !== 'case.created');

    $this->api('POST', '/api/v1/expedients', $input + [
        'copy_template' => true, 'template_status_id' => $input['status_id'],
    ], $headers)->assertInternalServerError();
    expect(Expedient::query()->count())->toBe(0);
    expect(ExpedientSequence::query()->count())->toBe(0);
    expect(Task::query()->count())->toBe(0);
    expect(Activity::query()->count())->toBe(0);
    $this->assertDatabaseEmpty('incidents');
});

test('case lifecycle audit describes only the actual cascades and retains permanently removed relationship IDs', function () {
    $headers = $this->authorizationBearer($this->authorizationAdmin());
    $case = Expedient::factory()->create();
    $task = Task::factory()->for($case)->create();
    $trashed = Task::factory()->for($case)->create(['deleted_at' => now()]);
    $comment = TaskComment::factory()->for($trashed)->create();
    $item = TaskChecklistItem::factory()->for($task)->create();
    $assignee = User::factory()->create();
    $task->assignees()->attach($assignee);
    $path = "/api/v1/expedients/{$case->id}";

    $this->api('DELETE', $path, headers: $headers)->assertOk();
    $this->api('POST', $path.'/restore', headers: $headers)->assertOk();
    $this->api('DELETE', $path, headers: $headers)->assertOk();
    $this->api('POST', $path.'/force-delete', headers: $headers)->assertOk();

    $restored = Activity::query()->where('event', 'task.restored')->sole();
    expect($restored->subject_id)->toBe($task->id);
    $removed = Activity::query()->where('event', 'case.descendants_removed')->get()
        ->mapWithKeys(fn (Activity $row) => $row->getProperty('metadata'))->all();
    expect($removed)->toEqual([
        'task_ids' => [$task->id, $trashed->id], 'comment_ids' => [$comment->id],
        'checklist_ids' => [$item->id], 'assignment_pairs' => [$task->id.':'.$assignee->id],
    ]);
    $event = Activity::query()->where('event', 'case.force_deleted')->sole();
    expect($event->subject_id)->toBe($case->id);
    expect($event->getProperty('case_code'))->toBe($case->code);
});
