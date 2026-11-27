<?php

use App\Models\Expedient;
use App\Models\ExpedientSequence;
use App\Models\ExpedientUserPreference;
use App\Models\Incident;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Support\CaseHttp;
use Tests\Support\RefreshGuardedMySql;

uses(CaseHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('case trash and restoration preserve task history and relations through repeated cascades', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $headers = $this->authorizationBearer($actor);
    $case = Expedient::factory()->create(['column_position' => 20, 'column_version' => 3]);
    $first = Task::factory()->for($case)->create(['position' => 12]);
    $prior = Task::factory()->for($case)->create();
    $prior->delete();
    $first->assignees()->attach($actor);
    $comment = TaskComment::factory()->for($first)->create();
    $check = TaskChecklistItem::factory()->for($first)->create();
    $tag = Tag::factory()->create();
    $case->tags()->attach($tag);
    $pref = ExpedientUserPreference::factory()->for($case)->for($actor)->create(['hidden' => true]);
    $path = "/api/v1/expedients/{$case->id}";
    $trash = "/api/v1/expedients/trash/{$case->id}";
    $this->api('GET', $path, headers: $headers)->assertOk();
    $this->api('GET', $trash, headers: $headers)->assertNotFound();
    $this->api('POST', "$path/restore", headers: $headers)->assertNotFound();

    for ($cycle = 0; $cycle < 2; $cycle++) {
        $this->api('DELETE', $path, headers: $headers)->assertOk()->assertJsonPath('code', 'case_deleted');
        $case->refresh();
        $first->refresh();
        expect($case->trashed())->toBeTrue()->and($first->trashed())->toBeTrue()
            ->and($first->deleted_by_expedient)->toBeTrue()->and($prior->fresh()->deleted_by_expedient)->toBeFalse();
        expect(Gate::forUser($actor)->allows('view', [$first, $case]))->toBeFalse();
        $this->api('GET', $path, headers: $headers)->assertNotFound();
        $this->api('PATCH', $path, ['name' => 'Inactive'], $headers)->assertNotFound();
        $this->api('DELETE', $path, headers: $headers)->assertNotFound();
        $this->api('GET', '/api/v1/expedients/trash', headers: $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', $trash, headers: $headers)->assertOk()->assertJsonStructure(['data' => ['deleted_at']]);

        $this->api('POST', "$path/restore", headers: $headers)->assertOk()
            ->assertJsonPath('data.column_position', 20)->assertJsonPath('data.column_version', 3)
            ->assertJsonMissingPath('data.deleted_at');
        expect($first->fresh()->trashed())->toBeFalse()->and($first->fresh()->deleted_by_expedient)->toBeFalse()
            ->and($first->fresh()->position)->toBe(12)->and($prior->fresh()->trashed())->toBeTrue();
        $this->api('POST', "$path/restore", headers: $headers)->assertNotFound();
    }

    $this->assertModelExists($comment);
    $this->assertModelExists($check);
    expect($first->assignees()->sole()->is($actor))->toBeTrue()
        ->and($case->tags()->sole()->is($tag))->toBeTrue()->and($pref->fresh()->hidden)->toBeTrue();
});

test('case restoration reserves available original task positions before resolving collisions', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $case = Expedient::factory()->create();
    $first = Task::factory()->for($case)->create(['position' => 5]);
    $second = Task::factory()->for($case)->create(['position' => 1, 'status_id' => $first->status_id]);
    $path = "/api/v1/expedients/{$case->id}";
    $this->api('DELETE', $path, headers: $headers)->assertOk();
    $replacement = Task::factory()->for($case)->create(['position' => 5, 'status_id' => $first->status_id]);

    $this->api('POST', "$path/restore", headers: $headers)->assertOk();
    expect($first->fresh()->position)->toBe(2)->and($second->fresh()->position)->toBe(1)
        ->and($replacement->fresh()->position)->toBe(5);
    expect($first->fresh()->deleted_by_expedient)->toBeFalse()->and($second->fresh()->deleted_by_expedient)->toBeFalse();
});

test('case deletion rollback keeps both the parent and tasks active if the parent operation is vetoed', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $case = Expedient::factory()->create();
    $task = Task::factory()->for($case)->create();
    Expedient::deleting(fn (): bool => false);

    $this->api('DELETE', "/api/v1/expedients/{$case->id}", headers: $headers)->assertInternalServerError()
        ->assertJsonPath('code', 'server_error')->assertJsonMissingPath('trace');
    $this->assertNotSoftDeleted($case);
    $this->assertNotSoftDeleted($task);
    expect($task->fresh()->deleted_by_expedient)->toBeFalse();
});

test('case restoration rolls back already restored children if the parent write is refused', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $case = Expedient::factory()->create();
    $task = Task::factory()->for($case)->create();
    $path = "/api/v1/expedients/{$case->id}";
    $this->api('DELETE', $path, headers: $headers)->assertOk();
    Expedient::restoring(fn (): bool => false);

    $this->api('POST', "$path/restore", headers: $headers)->assertInternalServerError();
    $this->assertSoftDeleted($case);
    $this->assertSoftDeleted($task);
    expect($task->fresh()->deleted_by_expedient)->toBeTrue();
});

test('permanent deletion requires an admin grant and cascades database rows while retaining history and storage', function (): void {
    $admin = $this->authorizationAdmin();
    $adminHeaders = $this->authorizationBearer($admin);
    $ordinary = User::factory()->create()->assignRole('user')->givePermissionTo('expedients.force-delete');
    $ordinaryHeaders = $this->authorizationBearer($ordinary);
    $input = $this->caseInput();
    $caseId = $this->api('POST', '/api/v1/expedients', $input, $adminHeaders)->assertCreated()->json('data.id');
    $case = Expedient::query()->findOrFail($caseId);
    $case->forceFill(['samba_relative_path' => 'synthetic/case'])->save();
    $storage = Storage::fake('samba');
    $storage->put('synthetic/case/sentinel.txt', 'Synthetic contents');
    $task = Task::factory()->for($case)->create();
    $task->assignees()->attach($ordinary);
    $comment = TaskComment::factory()->for($task)->create();
    $check = TaskChecklistItem::factory()->for($task)->create();
    $incident = Incident::factory()->for($case)->create(['expedient_code' => $case->code]);
    $preference = ExpedientUserPreference::factory()->for($case)->for($ordinary)->create();
    $tag = Tag::factory()->create();
    $case->tags()->attach($tag);
    $audit = DB::table('activity_log')->insertGetId(['description' => 'Synthetic prior audit', 'subject_type' => Expedient::class, 'subject_id' => $case->id]);
    $route = "/api/v1/expedients/{$case->id}";

    $this->api('POST', "$route/force-delete", headers: $adminHeaders)->assertNotFound();
    $this->api('DELETE', $route, headers: $ordinaryHeaders)->assertOk();
    $this->api('POST', "$route/force-delete", headers: $ordinaryHeaders)->assertForbidden();
    $role = Role::findByName('admin', 'web');
    $role->revokePermissionTo('expedients.force-delete');
    $this->api('POST', "$route/force-delete", headers: $adminHeaders)->assertForbidden();
    $role->givePermissionTo('expedients.force-delete');
    $this->api('POST', "$route/force-delete", headers: $adminHeaders)->assertOk()
        ->assertJsonPath('code', 'case_force_deleted')->assertJsonMissingPath('samba_relative_path');
    $this->api('POST', "$route/force-delete", headers: $adminHeaders)->assertNotFound();

    $this->assertModelMissing($case);
    $this->assertModelMissing($task);
    $this->assertModelMissing($comment);
    $this->assertModelMissing($check);
    $this->assertModelMissing($preference);
    $this->assertModelExists($tag);
    $this->assertDatabaseEmpty('task_user');
    $this->assertDatabaseEmpty('expedient_tag');
    $this->assertDatabaseHas('activity_log', ['id' => $audit, 'subject_id' => $case->id]);
    $this->assertDatabaseHas('incidents', ['id' => $incident->id, 'expedient_id' => null, 'expedient_code' => $case->code]);
    foreach (['clients' => 'requester_client_id', 'expedient_types' => 'expedient_type_id', 'statuses' => 'status_id'] as $table => $field) {
        $this->assertDatabaseHas($table, ['id' => $input[$field]]);
    }
    $storage->assertExists('synthetic/case/sentinel.txt');
    expect(ExpedientSequence::query()->findOrFail($case->year)->last_number)->toBe($case->sequence_number);
    $next = $this->api('POST', '/api/v1/expedients', $input, $adminHeaders)->assertCreated()->json('data.code');
    expect($next)->not->toBe($case->code)->and(ExpedientSequence::query()->findOrFail($case->year)->last_number)->toBe(2);
});
