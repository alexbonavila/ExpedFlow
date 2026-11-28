<?php

use App\Models\Expedient;
use App\Models\ExpedientUserPreference;
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

test('calendar shows only the actor’s active assigned civil dates, including shared tasks and midnight', function (): void {
    $a = User::factory()->create()->assignRole('user');
    $b = $this->authorizationAdmin();
    $aHeaders = $this->authorizationBearer($a);
    $bHeaders = $this->authorizationBearer($b);
    $case = Expedient::factory()->create();
    ExpedientUserPreference::factory()->for($case)->for($a)->create(['hidden' => true]);
    $taskRoot = "/api/v1/expedients/{$case->id}/tasks";
    $create = function (array $fields, array $users) use ($aHeaders, $case, $taskRoot): int {
        $id = $this->api('POST', $taskRoot, $fields + ['name' => 'Calendar task', 'status_id' => $case->status_id], $aHeaders)
            ->assertCreated()->json('data.id');
        $this->api('PUT', "/api/v1/tasks/$id/assignees", ['user_ids' => $users], $aHeaders)->assertOk();

        return $id;
    };
    $midnight = $create(['scheduled_date' => '2028-02-29', 'scheduled_time' => '00:00'], [$a->id, $b->id]);
    $allDay = $create(['scheduled_date' => '2028-02-29'], [$a->id]);
    $other = $create(['scheduled_date' => '2028-03-01'], [$b->id]);
    $create([], [$a->id]);
    $deleted = $create(['scheduled_date' => '2028-02-28'], [$a->id]);
    $this->api('DELETE', "/api/v1/tasks/$deleted", headers: $aHeaders)->assertOk();
    $deletedCase = Task::factory()->create(['scheduled_date' => '2028-02-28']);
    $deletedCase->assignees()->attach($a->id);
    $this->api('DELETE', "/api/v1/expedients/{$deletedCase->expedient_id}", headers: $aHeaders)->assertOk();

    $root = '/api/v1/calendar/tasks';
    $this->api('GET', $root, headers: $aHeaders)->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('links')
        ->assertJsonPath('data.0.id', $allDay)->assertJsonPath('data.0.all_day', true)->assertJsonPath('data.0.scheduled_time', null)
        ->assertJsonPath('data.0.expedient_code', $case->code)->assertJsonPath('data.0.expedient_name', $case->name)
        ->assertJsonPath('data.1.id', $midnight)->assertJsonPath('data.1.all_day', false)->assertJsonPath('data.1.scheduled_time', '00:00:00');
    $this->api('GET', $root, headers: $bHeaders)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $midnight)->assertJsonPath('data.1.id', $other);
    $this->api('GET', "$root?from=2028-02-29&to=2028-02-29", headers: $bHeaders)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $midnight);
    $this->api('GET', "$root?to=2028-02-28", headers: $aHeaders)->assertOk()->assertExactJson(['data' => []]);
    $this->api('GET', "$root?user_id={$a->id}", headers: $bHeaders)->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->api('GET', "$root?from=2028-03-02&to=2028-03-01", headers: $aHeaders)->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->api('GET', "$root?from=%202028-02-29", headers: $aHeaders)->assertUnprocessable()->assertJsonValidationErrors('from');
});

test('calendar requires all three grants before validating filters', function (): void {
    $role = Role::create(['name' => 'calendar-reader', 'guard_name' => 'web'])->givePermissionTo(['tasks.view', 'expedients.view']);
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole($role));
    $path = '/api/v1/calendar/tasks?from=invalid';
    $this->api('GET', $path)->assertUnauthorized();
    $this->api('GET', $path, headers: $headers)->assertForbidden();
    $role->givePermissionTo('calendar.view');
    $this->api('GET', $path, headers: $headers)->assertUnprocessable()->assertJsonValidationErrors('from');
    $role->revokePermissionTo('tasks.view');
    $this->api('GET', $path, headers: $headers)->assertForbidden();
    $role->givePermissionTo('tasks.view');
    $role->revokePermissionTo('expedients.view');
    $this->api('GET', $path, headers: $headers)->assertForbidden();
});
