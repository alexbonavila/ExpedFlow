<?php

use App\Models\Expedient;
use App\Models\Status;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\RefreshGuardedMySql;

uses(RefreshGuardedMySql::class);

test('a task persists its single civil date and distinguishes all day from midnight', function () {
    $task = Task::factory()->create()->refresh();

    expect($task->scheduled_date)->toBeNull();
    expect($task->scheduled_time)->toBeNull();
    expect($task->description)->toBeNull();

    $task->update(['scheduled_date' => '2026-10-25']);

    expect($task->refresh()->scheduled_date)->toBeInstanceOf(CarbonImmutable::class);
    expect($task->toArray()['scheduled_date'])->toBe('2026-10-25');
    expect($task->scheduled_time)->toBeNull();

    $task->update(['scheduled_time' => '00:00:00']);
    expect($task->refresh()->scheduled_time)->toBe('00:00:00');

    $task->update(['scheduled_time' => '23:59:59']);
    expect($task->refresh()->scheduled_time)->toBe('23:59:59');
});

test('MySQL rejects a task time without a date or outside one calendar day', function (?string $date, string $time) {
    $task = Task::factory()->create();

    expect(fn () => $task->update(['scheduled_date' => $date, 'scheduled_time' => $time]))
        ->toThrow(QueryException::class, 'tasks_schedule_check');
})->with([
    'no date' => [null, '00:00:00'],
    'negative time' => ['2026-10-25', '-00:00:01'],
    'past midnight' => ['2026-10-25', '24:00:00'],
]);

test('a task connects a case and status to optional assignees, comments and checklist records', function () {
    $case = Expedient::factory()->create();
    $status = Status::factory()->create();
    $task = Task::factory()->for($case)->for($status)->create();
    $people = User::factory()->count(2)->create();
    $author = $people->first();
    $comment = TaskComment::factory()->for($task)->for($author, 'author')->create();
    $item = TaskChecklistItem::factory()->for($task)->create(['completed' => 1]);

    expect($task->assignees()->count())->toBe(0);
    $task->assignees()->attach($people->modelKeys());

    expect($task->expedient->is($case))->toBeTrue();
    expect($task->status->is($status))->toBeTrue();
    expect($case->tasks()->sole()->is($task))->toBeTrue();
    expect($status->tasks()->sole()->is($task))->toBeTrue();
    expect($task->assignees()->get()->modelKeys())->toEqualCanonicalizing($people->modelKeys());
    expect($author->assignedTasks()->sole()->is($task))->toBeTrue();
    expect($task->comments()->sole()->is($comment))->toBeTrue();
    expect($comment->task->is($task))->toBeTrue();
    expect($comment->author->is($author))->toBeTrue();
    expect($author->taskComments()->sole()->is($comment))->toBeTrue();
    expect($task->checklistItems()->sole()->is($item))->toBeTrue();
    expect($item->task->is($task))->toBeTrue();
    expect($item->refresh()->completed)->toBeTrue();
    expect(fn () => $task->assignees()->attach($author))->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => DB::table('task_user')->insert(['task_id' => $task->id, 'user_id' => 0]))
        ->toThrow(QueryException::class, 'task_user_user_id_foreign');
    expect(fn () => DB::table('task_checklist_items')->where('id', $item->id)->update(['completed' => 2]))
        ->toThrow(QueryException::class, 'task_checklist_items_completed_check');
});

test('task positions are unique in the active case and status column', function () {
    $task = Task::factory()->create(['position' => 10]);
    $newTask = Task::factory()->for($task->expedient)->for($task->status);

    expect(fn () => $newTask->create(['position' => 10]))
        ->toThrow(UniqueConstraintViolationException::class, 'tasks_active_position_unique');
    expect(Task::factory()->for($task->expedient)->create(['position' => 10])->exists)->toBeTrue();

    $task->delete();
    $replacement = $newTask->create(['position' => 10]);

    expect($replacement->refresh()->active_position)->toBe(10);
    expect($task->refresh()->active_position)->toBeNull();
    expect(fn () => $task->restore())->toThrow(UniqueConstraintViolationException::class, 'tasks_active_position_unique');
});

test('soft deletion preserves dependencies and identifies tasks deleted with their case', function () {
    $case = Expedient::factory()->create();
    $cascadeTask = Task::factory()->for($case)->create();
    $olderTask = Task::factory()->for($case)->create();
    $author = User::factory()->create();
    $comment = TaskComment::factory()->for($cascadeTask, 'task')->for($author, 'author')->create();
    $item = TaskChecklistItem::factory()->for($cascadeTask, 'task')->create();
    $cascadeTask->assignees()->attach($author);

    expect(fn () => DB::table('tasks')->where('id', $cascadeTask->id)->update(['deleted_by_expedient' => true]))
        ->toThrow(QueryException::class, 'tasks_deleted_by_expedient_check');
    $olderTask->delete();
    $case->delete();
    $cascadeTask->forceFill(['deleted_at' => $case->deleted_at, 'deleted_by_expedient' => true])->save();
    $comment->delete();

    expect(Expedient::query()->find($case->id))->toBeNull();
    expect(Task::query()->find($cascadeTask->id))->toBeNull();
    expect(TaskComment::query()->find($comment->id))->toBeNull();
    expect($olderTask->refresh()->deleted_by_expedient)->toBeFalse();
    expect($cascadeTask->refresh()->deleted_by_expedient)->toBeTrue();
    expect($cascadeTask->toArray())->not->toHaveKeys(['active_position', 'deleted_by_expedient']);
    expect($case->tasks()->onlyTrashed()->where('deleted_by_expedient', true)->sole()->is($cascadeTask))->toBeTrue();
    expect($cascadeTask->assignees()->sole()->is($author))->toBeTrue();
    expect($cascadeTask->checklistItems()->sole()->is($item))->toBeTrue();
    expect($cascadeTask->comments()->withTrashed()->sole()->is($comment))->toBeTrue();
    expect(fn () => $author->delete())->toThrow(QueryException::class, 'task_comments_author_user_id_foreign');

    $case->restore();
    $cascadeTask->forceFill(['deleted_at' => null, 'deleted_by_expedient' => false])->save();

    expect($case->tasks()->sole()->is($cascadeTask))->toBeTrue();
    $this->assertSoftDeleted($olderTask);
    $this->assertSoftDeleted($comment);

    $case->forceDelete();

    $this->assertDatabaseMissing('tasks', ['id' => $cascadeTask->id]);
    $this->assertDatabaseMissing('tasks', ['id' => $olderTask->id]);
    $this->assertDatabaseMissing('task_user', ['task_id' => $cascadeTask->id]);
    $this->assertModelMissing($comment);
    $this->assertModelMissing($item);
    $this->assertModelExists($author);
});

test('a status referenced only by a soft-deleted task stays protected', function () {
    $task = Task::factory()->create();
    $status = $task->status;

    $task->delete();

    expect(fn () => $status->delete())->toThrow(QueryException::class, 'tasks_status_id_foreign');

    $task->forceDelete();
    expect($status->delete())->toBeTrue();
});
