<?php

use App\Models\Expedient;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\AuthorizationHttp;
use Tests\Support\RefreshGuardedMySql;

uses(AuthorizationHttp::class, RefreshGuardedMySql::class);

beforeEach(function () {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('task history records civil time and assignment changes without auditing reordered equivalent inputs', function () {
    $actor = User::factory()->create()->assignRole('user');
    $peer = User::factory()->create();
    $headers = $this->authorizationBearer($actor);
    $case = Expedient::factory()->create();
    $task = $this->api('POST', "/api/v1/expedients/{$case->id}/tasks", [
        'name' => 'Task named', 'description' => 'private-task-description', 'status_id' => $case->status_id,
        'scheduled_date' => '2028-02-29', 'scheduled_time' => '04:30',
    ], $headers)->assertCreated()->json('data.id');
    $path = "/api/v1/tasks/$task";
    $this->api('PATCH', $path, ['scheduled_time' => null], $headers)->assertOk();
    $this->api('PATCH', $path, ['scheduled_time' => null], $headers)->assertOk();
    $this->api('PUT', $path.'/assignees', ['user_ids' => [$peer->id, $actor->id]], $headers)->assertOk();
    $this->api('PUT', $path.'/assignees', ['user_ids' => [$actor->id, $peer->id]], $headers)->assertOk();
    $this->api('GET', $path, headers: $headers)->assertOk();

    $rows = Activity::query()->orderBy('id')->get();
    expect($rows->pluck('event')->all())->toBe(['task.created', 'task.updated', 'task.assignments_changed']);
    expect($rows[0]->attribute_changes->get('attributes')['scheduled_date'])->toBe('2028-02-29');
    expect($rows[1]->attribute_changes->get('old'))->toBe(['scheduled_time' => '04:30:00']);
    expect($rows[1]->attribute_changes->get('attributes'))->toBe(['scheduled_time' => null]);
    expect($rows[2]->getProperty('metadata.added_ids'))->toBe([$actor->id, $peer->id]);
    expect($rows[2]->getProperty('case_code'))->toBe($case->code);
    expect(json_encode(DB::table('activity_log')->get()))->not->toContain('private-task-description');
});

test('comment edit records the editor and original author and persists only a body marker', function () {
    $task = Task::factory()->create();
    $author = User::factory()->create()->assignRole('user');
    $editor = User::factory()->create()->assignRole('user');
    $path = "/api/v1/tasks/{$task->id}/comments";
    $id = $this->api('POST', $path, ['body' => 'private-original-body'], $this->authorizationBearer($author))
        ->assertCreated()->json('data.id');
    $headers = $this->authorizationBearer($editor);
    $this->api('PATCH', "$path/$id", ['body' => 'private-edited-body'], $headers)->assertOk();
    $this->api('PATCH', "$path/$id", ['body' => 'private-edited-body'], $headers)->assertOk();
    $this->api('DELETE', "$path/$id", headers: $headers)->assertOk();
    $this->api('POST', "$path/$id/restore", headers: $headers)->assertOk();

    $rows = Activity::query()->orderBy('id')->get();
    expect($rows->pluck('event')->all())->toBe(['comment.created', 'comment.updated', 'comment.deleted', 'comment.restored']);
    expect($rows[1]->causer_id)->toBe($editor->id);
    expect($rows[1]->getProperty('metadata.author_id'))->toBe($author->id);
    expect($rows[1]->attribute_changes->get('attributes'))->toBe(['body' => ['changed' => true]]);
    expect(json_encode(DB::table('activity_log')->get()))->not->toContain('private-original-body', 'private-edited-body');
});

test('checklist reorder records final changed positions and completion while repeating the order emits nothing', function () {
    $task = Task::factory()->create();
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $path = "/api/v1/tasks/{$task->id}/checklist";
    $first = $this->api('POST', $path, ['name' => 'First'], $headers)->assertCreated()->json('data.id');
    $second = $this->api('POST', $path, ['name' => 'Second'], $headers)->assertCreated()->json('data.id');
    $this->api('PUT', $path.'/order', ['item_ids' => [$second, $first]], $headers)->assertOk();
    $this->api('PUT', $path.'/order', ['item_ids' => [$second, $first]], $headers)->assertOk();
    $this->api('PATCH', "$path/$first", ['completed' => true], $headers)->assertOk();
    $this->api('DELETE', "$path/$first", headers: $headers)->assertOk();

    $rows = Activity::query()->orderBy('id')->get();
    expect($rows->pluck('event')->all())->toBe([
        'checklist.created', 'checklist.created', 'checklist.reordered', 'checklist.reordered', 'checklist.updated', 'checklist.deleted',
    ]);
    expect($rows[2]->subject_id)->toBe($second);
    expect($rows[2]->attribute_changes->get('attributes'))->toBe(['position' => 1]);
    expect($rows[4]->attribute_changes->get('attributes'))->toBe(['completed' => true]);
});

test('a move records the changed logical rank when compaction reuses the original stored position', function () {
    $case = Expedient::factory()->create();
    $task = Task::factory()->for($case)->create(['status_id' => $case->status_id, 'position' => 3]);
    Task::factory()->for($case)->create(['status_id' => $case->status_id, 'position' => 4]);
    Task::factory()->for($case)->create(['status_id' => $case->status_id, 'position' => 5]);
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));

    $this->api('POST', "/api/v1/tasks/{$task->id}/move", ['status_id' => $case->status_id, 'position' => 3], $headers)->assertOk();
    expect($task->refresh()->position)->toBe(3);
    $event = Activity::query()->sole();
    expect($event->event)->toBe('task.moved');
    expect($event->attribute_changes->get('old'))->toBe(['rank' => 1]);
    expect($event->attribute_changes->get('attributes'))->toBe(['rank' => 3]);
});
