<?php

use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\ExpedientUserPreference;
use App\Models\Status;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Support\AuthorizationCatalog;
use App\Support\CaseOrdering;
use App\Support\ExpedientLifecycle;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\CaseHttp;
use Tests\Support\TestingDatabase;

uses(CaseHttp::class);

beforeEach(function (): void {
    TestingDatabase::assertSafe($this->app);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

test('a case committed while type deletion waits makes the reference conflict current', function (): void {
    $creator = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $manager = $this->authorizationBearer($this->authorizationAdmin());
    $input = $this->caseInput(['expedient_type_id' => ExpedientType::factory()->create()->id]);
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = $this->commitAgainstCaseContender([
        'method' => 'DELETE', 'path' => '/api/v1/expedient-types/'.$input['expedient_type_id'],
        'authorization' => $manager['Authorization'], 'barrier' => 'for update',
    ], fn () => $this->api('POST', '/api/v1/expedients', $input, $creator)->assertCreated());

    expect($result['status'])->toBe(409)->and($result['code'])->toBe('type_referenced');
    $this->assertDatabaseCount('expedients', 1);
    $this->assertDatabaseHas('expedient_types', ['id' => $input['expedient_type_id']]);
});

test('concurrent inserts cannot allocate the same exact type code', function (): void {
    $manager = $this->authorizationBearer($this->authorizationAdmin());
    DB::beginTransaction();
    ExpedientType::factory()->create(['code' => '03']);

    $result = $this->commitAgainstCaseContender([
        'method' => 'POST', 'path' => '/api/v1/expedient-types',
        'authorization' => $manager['Authorization'], 'barrier' => 'insert into `expedient_types`',
        'body' => ['name' => 'Contending type', 'code' => '03'],
    ]);

    expect($result['status'])->toBe(409)->and($result['code'])->toBe('type_code_conflict');
    $this->assertDatabaseCount('expedient_types', 1);
});

test('a task written with the shared parent and status locks blocks status deletion', function (): void {
    $admin = $this->authorizationBearer($this->authorizationAdmin());
    $case = Expedient::factory()->create();
    $status = Status::factory()->create();
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = $this->commitAgainstCaseContender([
        'method' => 'DELETE', 'path' => "/api/v1/statuses/{$status->id}",
        'authorization' => $admin['Authorization'], 'barrier' => 'for update',
    ], function () use ($case, $status): void {
        $parent = app(ExpedientLifecycle::class)->lockParent($case->id);
        app(CaseOrdering::class)->lockStatuses($status->id);
        Task::factory()->create(['expedient_id' => $parent->id, 'status_id' => $status->id]);
    });

    expect($result['status'])->toBe(409)->and($result['code'])->toBe('status_referenced');
    $this->assertModelExists($status);
});

test('template edits and a competing case snapshot serialize at the guarded write boundary', function (): void {
    $editor = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $creator = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $input = $this->caseInput();
    $template = TaskTemplate::factory()->create(['expedient_type_id' => $input['expedient_type_id'], 'name' => 'Previous']);
    $path = "/api/v1/expedient-types/{$template->expedient_type_id}/task-templates/{$template->id}";
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = $this->commitAgainstCaseContender([
        'method' => 'POST', 'path' => '/api/v1/expedients', 'authorization' => $creator['Authorization'], 'barrier' => 'for update',
        'body' => $input + ['copy_template' => true, 'template_status_id' => $input['status_id']],
    ], fn () => $this->api('PATCH', $path, ['name' => 'Committed name'], $editor)->assertOk());

    expect($result['status'])->toBe(201);
    expect(Task::query()->sole()->name)->toBe('Committed name');
});

test('deleted copy status causes rollback when case creation follows the deleting transaction', function (): void {
    $admin = $this->authorizationBearer($this->authorizationAdmin());
    $creator = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $input = $this->caseInput();
    $status = Status::factory()->create();
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = $this->commitAgainstCaseContender([
        'method' => 'POST', 'path' => '/api/v1/expedients', 'authorization' => $creator['Authorization'], 'barrier' => 'for update',
        'body' => $input + ['copy_template' => true, 'template_status_id' => $status->id],
    ], fn () => $this->api('DELETE', "/api/v1/statuses/{$status->id}", headers: $admin)->assertOk());

    expect($result['status'])->toBe(422);
    $this->assertDatabaseEmpty('expedients');
    $this->assertDatabaseEmpty('expedient_sequences');
    $this->assertDatabaseEmpty('tasks');
});

test('a tag deleted while replacement waits leaves previous membership intact', function (): void {
    $manager = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $editor = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $case = Expedient::factory()->create();
    $previous = Tag::factory()->create();
    $deleted = Tag::factory()->create();
    $case->tags()->attach($previous);
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = $this->commitAgainstCaseContender([
        'method' => 'PUT', 'path' => "/api/v1/expedients/{$case->id}/tags",
        'authorization' => $editor['Authorization'], 'barrier' => 'for update', 'body' => ['tag_ids' => [$deleted->id]],
    ], fn () => $this->api('DELETE', "/api/v1/tags/{$deleted->id}", headers: $manager)->assertOk());

    expect($result['status'])->toBe(422);
    expect($case->tags()->sole()->id)->toBe($previous->id);
});

test('competing personal moves reconcile from current ranks and keep shared case order', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $a = $this->authorizationBearer($actor);
    $b = $this->authorizationBearer($actor);
    $status = Status::factory()->create();
    [$first, $second, $third] = array_map(fn (int $rank): Expedient => Expedient::factory()
        ->create(['status_id' => $status->id, 'column_position' => $rank]), [1, 2, 3]);
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = $this->commitAgainstCaseContender([
        'method' => 'POST', 'path' => "/api/v1/expedients/{$second->id}/move",
        'authorization' => $b['Authorization'], 'barrier' => 'for update', 'body' => ['status_id' => $status->id],
    ], fn () => $this->api('POST', "/api/v1/expedients/{$first->id}/move", ['status_id' => $status->id], $a)->assertOk());

    expect($result['status'])->toBe(200);
    expect(ExpedientUserPreference::query()->orderBy('position')->pluck('expedient_id')->all())
        ->toBe([$third->id, $first->id, $second->id]);
    expect(Expedient::query()->orderBy('column_position')->pluck('id')->all())->toBe([$first->id, $second->id, $third->id]);
});

test('competing cross-column moves each append globally and have independent actor ranks', function (): void {
    $a = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $b = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $first = Expedient::factory()->create();
    $second = Expedient::factory()->create();
    $status = Status::factory()->create();
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = $this->commitAgainstCaseContender([
        'method' => 'POST', 'path' => "/api/v1/expedients/{$second->id}/move",
        'authorization' => $b['Authorization'], 'barrier' => 'for update',
        'body' => ['status_id' => $status->id, 'position' => 1],
    ], fn () => $this->api('POST', "/api/v1/expedients/{$first->id}/move", ['status_id' => $status->id], $a)->assertOk());

    expect($result['status'])->toBe(200)->and($result['data']['position'])->toBe(1);
    expect($first->fresh()->column_position)->toBe(1)->and($second->fresh()->column_position)->toBe(2);
    expect($first->fresh()->column_version)->toBe(2)->and($second->fresh()->column_version)->toBe(2);
    $this->assertDatabaseCount('expedient_user_preferences', 3);
});

test('a board write admitted before revocation rechecks permissions and credentials', function (bool $token): void {
    $role = Role::create(['name' => 'Personal board role', 'guard_name' => 'web']);
    $role->givePermissionTo('kanban.manage', 'expedients.view');
    $actor = User::factory()->create()->assignRole($role);
    $headers = $this->authorizationBearer($actor);
    $admin = $this->authorizationBearer($this->authorizationAdmin());
    $case = Expedient::factory()->create();
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = $this->commitAgainstCaseContender([
        'method' => 'PATCH', 'path' => "/api/v1/expedients/{$case->id}/preferences",
        'authorization' => $headers['Authorization'], 'barrier' => 'for update', 'body' => ['hidden' => true],
    ], function () use ($token, $headers, $admin, $role): void {
        if ($token) {
            $this->api('DELETE', '/api/v1/auth/token', headers: $headers)->assertOk();
        } else {
            $this->api('PUT', "/api/v1/roles/{$role->id}/permissions", ['permissions' => ['expedients.view']], $admin)->assertOk();
        }
    });

    expect($result['status'])->toBe($token ? 401 : 403);
    $this->assertDatabaseEmpty('expedient_user_preferences');
})->with(['credential revoked' => true, 'grant revoked' => false]);

test('the type foreign key protects a reference committed after the policy snapshot starts', function (): void {
    $manager = $this->authorizationBearer($this->authorizationAdmin());
    $type = ExpedientType::factory()->create();
    DB::beginTransaction();
    $case = Expedient::factory()->create(['expedient_type_id' => $type->id]);

    $result = $this->commitAgainstCaseContender([
        'method' => 'DELETE', 'path' => "/api/v1/expedient-types/{$type->id}",
        'authorization' => $manager['Authorization'], 'barrier' => 'select * from `expedient_types`',
    ]);

    expect($result['status'])->toBe(409)->and($result['code'])->toBe('type_referenced');
    $this->assertModelExists($case);
    $this->assertModelExists($type);
});

test('status deletion and an actual task move see the committed reference in either order', function (bool $moveFirst): void {
    $admin = $this->authorizationBearer($this->authorizationAdmin());
    $actor = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $case = Expedient::factory()->create();
    $destination = Status::factory()->create();
    $taskId = $this->api('POST', "/api/v1/expedients/{$case->id}/tasks",
        ['name' => 'Moving task', 'status_id' => $case->status_id], $actor)->assertCreated()->json('data.id');
    $move = "/api/v1/tasks/$taskId/move";
    $catalog = "/api/v1/statuses/{$destination->id}";
    $input = ['status_id' => $destination->id];
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    if ($moveFirst) {
        $result = $this->commitAgainstCaseContender([
            'method' => 'DELETE', 'path' => $catalog, 'authorization' => $admin['Authorization'], 'barrier' => 'for update',
        ], fn () => $this->api('POST', $move, $input, $actor)->assertOk());
        expect($result['status'])->toBe(409)->and($result['code'])->toBe('status_referenced');
        $this->assertModelExists($destination);
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'status_id' => $destination->id, 'position' => 1]);
    } else {
        $result = $this->commitAgainstCaseContender([
            'method' => 'POST', 'path' => $move, 'authorization' => $actor['Authorization'],
            'barrier' => 'for update', 'body' => $input,
        ], fn () => $this->api('DELETE', $catalog, headers: $admin)->assertOk());
        expect($result['status'])->toBe(422)->and($result['code'])->toBe('validation_failed');
        $this->assertModelMissing($destination);
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'status_id' => $case->status_id, 'position' => 1]);
    }
})->with(['task move first' => true, 'status removal first' => false]);

test('a cross-column personal board move serializes with a task move under the same case', function (): void {
    $owner = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $editor = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $case = Expedient::factory()->create();
    $destination = Status::factory()->create();
    $task = Task::factory()->create(['expedient_id' => $case->id, 'status_id' => $destination->id]);
    $casePath = "/api/v1/expedients/{$case->id}";
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = $this->commitAgainstCaseContender([
        'method' => 'POST', 'path' => "/api/v1/tasks/{$task->id}/move", 'authorization' => $editor['Authorization'],
        'barrier' => 'for update', 'body' => ['status_id' => $case->status_id],
    ], fn () => $this->api('POST', "$casePath/move", ['status_id' => $destination->id, 'position' => 1], $owner)
        ->assertOk()->assertJsonPath('data.status_id', $destination->id));

    expect($result['status'])->toBe(200)->and($result['data']['status_id'])->toBe($case->status_id);
    $this->api('GET', "$casePath/tasks", headers: $owner)->assertOk()->assertJsonPath('data.0.position', 1)
        ->assertJsonPath('data.0.id', $task->id)->assertJsonPath('data.0.status_id', $case->status_id);
    expect($case->fresh()->status_id)->toBe($destination->id);
    $this->api('GET', "$casePath/preferences", headers: $owner)->assertOk()->assertJsonPath('data.position', 1);
    $this->api('GET', "$casePath/preferences", headers: $editor)->assertOk()->assertJsonPath('data.position', null);
});
