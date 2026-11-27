<?php

use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\ExpedientUserPreference;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\RefreshGuardedMySql;

uses(RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
});

test('ordinary case and task access is global and never relies on task assignment', function (): void {
    $user = User::factory()->create()->assignRole('user');
    $capabilities = Gate::forUser($user);
    $task = Task::factory()->create();
    $case = $task->expedient;
    $other = Expedient::factory()->create();

    expect($capabilities->allows('viewAny', Expedient::class))->toBeTrue()
        ->and($capabilities->allows('create', Expedient::class))->toBeTrue()
        ->and($capabilities->allows('view', $case))->toBeTrue()
        ->and($capabilities->allows('update', $case))->toBeTrue()
        ->and($capabilities->allows('viewAny', [Task::class, $case]))->toBeTrue()
        ->and($capabilities->allows('create', [Task::class, $case]))->toBeTrue()
        ->and($capabilities->allows('associateTags', $case))->toBeTrue()
        ->and($capabilities->allows('upload', $case))->toBeTrue();
    foreach (['view', 'update', 'assign', 'reorder'] as $ability) {
        expect($capabilities->allows($ability, [$task, $case]))->toBeTrue()
            ->and($capabilities->allows($ability, [$task, $other]))->toBeFalse();
    }
    $case->delete();
    foreach (['view', 'update', 'associateTags', 'upload'] as $ability) {
        expect($capabilities->allows($ability, $case))->toBeFalse();
    }
    expect($capabilities->allows('create', [Task::class, $case]))->toBeFalse()
        ->and($capabilities->allows('viewAny', [Task::class, $case]))->toBeFalse();
});

test('restricted custom roles lose domain capabilities and administrator-only grants remain inert', function (): void {
    $role = Role::query()->create(['name' => 'Case Viewer', 'guard_name' => 'web']);
    $role->givePermissionTo('expedients.view');
    $viewer = User::factory()->create()->assignRole($role)->givePermissionTo(['statuses.manage', 'roles.manage']);
    $viewer->givePermissionTo(Permission::query()->create(['name' => 'update', 'guard_name' => 'web']));
    $case = Expedient::factory()->create();
    $task = Task::factory()->for($case)->create();
    $gate = Gate::forUser($viewer);
    expect($gate->allows('view', $case))->toBeTrue()
        ->and($gate->allows('update', $case))->toBeFalse()
        ->and($gate->allows('viewAny', [Task::class, $case]))->toBeFalse()
        ->and($gate->allows('create', [Task::class, $case]))->toBeFalse()
        ->and($gate->allows('associateTags', $case))->toBeFalse()
        ->and($gate->allows('upload', $case))->toBeFalse()
        ->and($gate->allows('statuses.manage'))->toBeFalse()
        ->and($gate->allows('view', [$task, $case]))->toBeFalse()
        ->and($gate->allows('incidents.view'))->toBeFalse();
    $role->givePermissionTo('tasks.view');
    expect($gate->allows('view', [$task, $case]))->toBeTrue();
    foreach (['update', 'assign', 'reorder'] as $ability) {
        expect($gate->allows($ability, [$task, $case]))->toBeFalse();
    }

    $viewer->forceFill(['disabled_at' => now()])->save();
    expect($gate->allows('view', $case))->toBeFalse();

    $ordinary = Gate::forUser(User::factory()->create()->assignRole('user'));
    $admin = Gate::forUser(User::factory()->create()->assignRole('admin'));
    foreach (['clients.view', 'clients.manage', 'expedient-types.view', 'expedient-types.manage', 'statuses.view', 'tags.view', 'tags.manage', 'incidents.view'] as $ability) {
        expect($ordinary->allows($ability))->toBeTrue();
    }
    expect($ordinary->allows('statuses.manage'))->toBeFalse()->and($admin->allows('statuses.manage'))->toBeTrue();
});

test('calendar requires an active dated task assigned to the calendar owner', function (): void {
    $user = User::factory()->create()->assignRole('user');
    $other = User::factory()->create()->assignRole('admin');
    $task = Task::factory()->create(['scheduled_date' => '2026-12-01']);
    $case = $task->expedient;
    $ability = Gate::forUser($user);
    expect($ability->allows('calendar', [$task, $case, $user]))->toBeFalse();
    $task->assignees()->attach([$user->id, $other->id]);
    expect($ability->allows('calendar', [$task, $case, $user]))->toBeTrue()
        ->and($ability->allows('calendar', [$task, $case, $other]))->toBeFalse()
        ->and(Gate::forUser($other)->allows('calendar', [$task, $case, $other]))->toBeTrue();
    $task->forceFill(['scheduled_date' => null])->save();
    expect($ability->allows('calendar', [$task, $case, $user]))->toBeFalse();
    $task->forceFill(['scheduled_date' => '2026-12-01'])->save();
    $task->delete();
    expect($ability->allows('calendar', [$task, $case, $user]))->toBeFalse();
    $task->restore();
    Role::findByName('user', 'web')->revokePermissionTo('calendar.view');
    expect($ability->allows('calendar', [$task, $case, $user]))->toBeFalse();
});

test('Kanban preference changes require the actual owner and a visible active case', function (): void {
    $staff = User::factory()->create()->assignRole('user');
    $admin = User::factory()->create()->assignRole('admin');
    $own = ExpedientUserPreference::factory()->for($staff)->create();
    $case = $own->expedient;
    $other = Expedient::factory()->create();
    $gate = Gate::forUser($staff);
    expect($gate->allows('manage', [ExpedientUserPreference::class, $case, $staff]))->toBeTrue()
        ->and($gate->allows('manage', [ExpedientUserPreference::class, $case, $staff, $own]))->toBeTrue()
        ->and($gate->allows('manage', [ExpedientUserPreference::class, $other, $staff, $own]))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('manage', [ExpedientUserPreference::class, $case, $staff, $own]))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('manage', [ExpedientUserPreference::class, $case, $admin, $own]))->toBeFalse();
    Role::findByName('user', 'web')->revokePermissionTo('kanban.manage');
    expect($gate->allows('manage', [ExpedientUserPreference::class, $case, $staff]))->toBeFalse();
});

test('task comments and checklists remain scoped to their task even for administrators', function (): void {
    $user = User::factory()->create()->assignRole('user');
    $task = Task::factory()->create();
    $case = $task->expedient;
    $other = Task::factory()->for($case)->create();
    $comment = TaskComment::factory()->for($task)->create();
    $check = TaskChecklistItem::factory()->for($task)->create();
    $gate = Gate::forUser($user);
    expect($gate->allows('create', [TaskComment::class, $task, $case]))->toBeTrue()
        ->and($gate->allows('view', [$comment, $task, $case]))->toBeTrue()
        ->and($gate->allows('update', [$comment, $task, $case]))->toBeTrue()
        ->and($gate->allows('manage', [TaskChecklistItem::class, $task, $case]))->toBeTrue()
        ->and($gate->allows('manage', [TaskChecklistItem::class, $task, $case, $check]))->toBeTrue();
    $admin = Gate::forUser(User::factory()->create()->assignRole('admin'));
    expect($admin->allows('update', [$comment, $other, $case]))->toBeFalse()
        ->and($admin->allows('manage', [TaskChecklistItem::class, $other, $case, $check]))->toBeFalse();
    $task->delete();
    expect($gate->allows('create', [TaskComment::class, $task, $case]))->toBeFalse()
        ->and($gate->allows('view', [$comment, $task, $case]))->toBeFalse()
        ->and($gate->allows('manage', [TaskChecklistItem::class, $task, $case, $check]))->toBeFalse();
});

test('templates and checklist actions enforce both parent relationships', function (): void {
    $user = User::factory()->create()->assignRole('user');
    $template = TaskTemplate::factory()->create();
    $type = $template->type;
    $otherType = ExpedientType::factory()->create();
    $item = TaskTemplateChecklistItem::factory()->create();
    $gate = Gate::forUser($user);
    expect($gate->allows('viewAny', [TaskTemplate::class, $type]))->toBeTrue()
        ->and($gate->allows('create', [TaskTemplate::class, $type]))->toBeTrue()
        ->and($gate->allows('view', [$template, $type]))->toBeTrue()
        ->and($gate->allows('view', [$template, $otherType]))->toBeFalse()
        ->and($gate->allows('update', [$template, $type]))->toBeTrue()
        ->and($gate->allows('update', [$template, $otherType]))->toBeFalse()
        ->and($gate->allows('manageChecklist', [$template, $type]))->toBeTrue()
        ->and($gate->allows('manageChecklist', [$template, $type, $item]))->toBeFalse()
        ->and($gate->allows('manageChecklist', [$item->template, $item->template->type, $item]))->toBeTrue();
    Role::findByName('user', 'web')->revokePermissionTo(['task-templates.view', 'task-templates.manage']);
    expect($gate->allows('view', [$template, $type]))->toBeFalse()
        ->and($gate->allows('update', [$template, $type]))->toBeFalse();
});
