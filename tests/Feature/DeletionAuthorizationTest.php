<?php

use App\Models\Client;
use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Status;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\TaskTemplate;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\Support\RefreshGuardedMySql;

uses(RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
});

test('ordinary authorized users can soft delete and restore cases while force deletion requires both admin and grant', function (): void {
    $ordinary = User::factory()->create()->assignRole('user')->givePermissionTo('expedients.force-delete');
    $admin = User::factory()->create()->assignRole('admin');
    $roleless = User::factory()->create();
    $gate = Gate::forUser($ordinary);
    $adminGate = Gate::forUser($admin);
    $case = Expedient::factory()->create(['samba_relative_path' => 'synthetic/case']);
    expect($gate->allows('delete', $case))->toBeTrue()
        ->and($gate->allows('restore', $case))->toBeFalse()
        ->and($gate->allows('viewTrash', $case))->toBeFalse()
        ->and($adminGate->allows('forceDelete', $case))->toBeFalse()
        ->and(Gate::forUser($roleless)->allows('delete', $case))->toBeFalse();

    $case->delete();

    expect($gate->allows('view', $case))->toBeFalse()
        ->and($gate->allows('delete', $case))->toBeFalse()
        ->and($gate->allows('restore', $case))->toBeTrue()
        ->and($gate->allows('viewAnyTrash', Expedient::class))->toBeTrue()
        ->and($gate->allows('viewTrash', $case))->toBeTrue()
        ->and($gate->allows('forceDelete', $case))->toBeFalse()
        ->and($adminGate->allows('forceDelete', $case))->toBeTrue()
        ->and(Gate::forUser($roleless)->allows('viewTrash', $case))->toBeFalse();
    $admin->roles()->sole()->revokePermissionTo('expedients.force-delete');
    expect($adminGate->allows('forceDelete', $case))->toBeFalse();
    $this->assertSoftDeleted($case);
    expect($case->samba_relative_path)->toBe('synthetic/case');
});

test('parent restoration authorizes only its own marked cascade tasks', function (): void {
    $ordinary = User::factory()->create()->assignRole('user');
    $admin = User::factory()->create()->assignRole('admin');
    $gate = Gate::forUser($ordinary);
    $marked = Task::factory()->create();
    $case = $marked->expedient;
    $prior = Task::factory()->for($case)->create();
    $active = Task::factory()->for($case)->create();

    $marked->delete();
    $marked->forceFill(['deleted_by_expedient' => true])->save();
    $prior->delete();
    $unrelated = Task::factory()->create();
    $unrelated->delete();
    $unrelated->forceFill(['deleted_by_expedient' => true])->save();

    expect($gate->allows('restoreCascadeTask', [$case, $marked]))->toBeFalse()
        ->and($gate->allows('restore', [$marked, $case]))->toBeFalse()
        ->and($gate->allows('restore', [$prior, $case]))->toBeTrue();

    $case->delete();

    expect($gate->allows('restoreCascadeTask', [$case, $marked]))->toBeTrue()
        ->and($gate->allows('restoreCascadeTask', [$case, $prior]))->toBeFalse()
        ->and($gate->allows('restoreCascadeTask', [$case, $active]))->toBeFalse()
        ->and($gate->allows('restoreCascadeTask', [$case, $unrelated]))->toBeFalse()
        ->and($gate->allows('restore', [$prior, $case]))->toBeFalse()
        ->and($gate->allows('viewTrash', [$marked, $case]))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('forceDelete', [$marked, $case]))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('view', [$active, $case]))->toBeFalse();
    $this->assertSoftDeleted($marked);
    $this->assertSoftDeleted($prior);
    $this->assertNotSoftDeleted($active);
});

test('tasks and comments require explicit trash privileges and matching active parents', function (): void {
    $user = User::factory()->create()->assignRole('user')->givePermissionTo(['tasks.force-delete', 'task-comments.force-delete']);
    $admin = User::factory()->create()->assignRole('admin');
    $gate = Gate::forUser($user);
    $adminGate = Gate::forUser($admin);
    $task = Task::factory()->create();
    $case = $task->expedient;
    $other = Task::factory()->create();
    $comment = TaskComment::factory()->for($task)->create();
    $commentContext = [$comment, $task, $case];
    $taskContext = [$task, $case];

    foreach ([$taskContext, $commentContext] as $context) {
        expect($gate->allows('delete', $context))->toBeTrue()
            ->and($gate->allows('restore', $context))->toBeFalse()
            ->and($gate->allows('viewTrash', $context))->toBeFalse()
            ->and($adminGate->allows('forceDelete', $context))->toBeFalse();
    }

    $comment->delete();
    expect($gate->allows('viewAnyTrash', [TaskComment::class, $task, $case]))->toBeTrue()
        ->and($gate->allows('viewTrash', $commentContext))->toBeTrue()
        ->and($gate->allows('view', $commentContext))->toBeFalse()
        ->and($gate->allows('delete', $commentContext))->toBeFalse()
        ->and($gate->allows('restore', $commentContext))->toBeTrue()
        ->and($gate->allows('forceDelete', $commentContext))->toBeFalse()
        ->and($adminGate->allows('forceDelete', $commentContext))->toBeTrue()
        ->and($adminGate->allows('forceDelete', [$comment, $other, $other->expedient]))->toBeFalse();
    $task->delete();
    expect($gate->allows('viewAnyTrash', [Task::class, $case]))->toBeTrue()
        ->and($gate->allows('viewTrash', $taskContext))->toBeTrue()
        ->and($gate->allows('delete', $taskContext))->toBeFalse()
        ->and($gate->allows('restore', $taskContext))->toBeTrue()
        ->and($gate->allows('forceDelete', $taskContext))->toBeFalse()
        ->and($adminGate->allows('forceDelete', $taskContext))->toBeTrue()
        ->and($adminGate->allows('forceDelete', [$task, $other->expedient]))->toBeFalse()
        ->and($adminGate->allows('forceDelete', $commentContext))->toBeFalse();
});

test('catalog removal is physical and protects live and soft-deleted references', function (): void {
    $ordinary = Gate::forUser(User::factory()->create()->assignRole('user'));
    $admin = Gate::forUser(User::factory()->create()->assignRole('admin'));
    $case = Expedient::factory()->create(['represented_client_id' => Client::factory()]);
    $case->delete();
    $task = Task::factory()->create();
    $task->delete();
    $template = TaskTemplate::factory()->create();

    expect($ordinary->allows('delete', Client::factory()->create()))->toBeTrue()
        ->and($ordinary->allows('delete', $case->requester))->toBeFalse()
        ->and($ordinary->allows('delete', $case->representedClient))->toBeFalse()
        ->and($ordinary->allows('delete', ExpedientType::factory()->create()))->toBeTrue()
        ->and($ordinary->allows('delete', $case->type))->toBeFalse()
        ->and($ordinary->allows('delete', $template->type))->toBeFalse()
        ->and($ordinary->allows('delete', [$template, $template->type]))->toBeTrue()
        ->and($ordinary->allows('delete', Tag::factory()->create()))->toBeTrue()
        ->and($ordinary->allows('delete', Status::factory()->create()))->toBeFalse()
        ->and($admin->allows('delete', Status::factory()->create()))->toBeTrue()
        ->and($admin->allows('delete', $case->status))->toBeFalse()
        ->and($admin->allows('delete', $task->status))->toBeFalse();

    foreach ([$case->type, $case->requester, $case->status, Tag::factory()->create(), $template, TaskChecklistItem::factory()->create()] as $catalog) {
        expect($admin->allows('restore', $catalog))->toBeFalse()
            ->and($admin->allows('forceDelete', $catalog))->toBeFalse();
    }
});

test('an administrator may have explicit force deletion and trash visibility without an ordinary restore grant', function (): void {
    $role = Role::findByName('admin', 'web');
    $role->revokePermissionTo(['expedients.restore', 'tasks.restore', 'task-comments.restore']);
    $user = User::factory()->create()->assignRole($role);
    $gate = Gate::forUser($user);
    $case = Expedient::factory()->create();
    $case->delete();
    $task = Task::factory()->create();
    $task->delete();
    $comment = TaskComment::factory()->create();
    $comment->delete();
    foreach ([$case, [$task, $task->expedient], [$comment, $comment->task, $comment->task->expedient]] as $context) {
        expect($gate->allows('restore', $context))->toBeFalse()
            ->and($gate->allows('forceDelete', $context))->toBeTrue()
            ->and($gate->allows('viewTrash', $context))->toBeTrue();
    }
});
