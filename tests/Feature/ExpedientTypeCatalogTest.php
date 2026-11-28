<?php

use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\TaskTemplate;
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

test('delegated type managers can maintain names and codes remain exact and immutable', function (): void {
    $role = Role::create(['name' => 'Type editor', 'guard_name' => 'web']);
    $role->givePermissionTo('expedient-types.manage', 'expedient-types.view');
    $headers = $this->authorizationBearer(User::factory()->create(['locale' => 'ca'])->assignRole($role));
    $list = '/api/v1/expedient-types';
    $type = $this->api('POST', $list, ['name' => "\u{00a0}First type\u{2003}", 'code' => '00'], $headers)
        ->assertCreated()->assertJsonPath('data.code', '00')->assertJsonPath('data.name', 'First type');
    $detail = $list.'/'.$type->json('data.id');

    $this->api('GET', $detail, headers: $headers)->assertExactJson($type->json());
    $this->api('POST', $list, ['name' => 'Duplicate', 'code' => '00'], $headers)
        ->assertConflict()->assertJsonPath('code', 'type_code_conflict')
        ->assertJsonPath('message', 'Ja existeix aquest codi de tipus d’expedient.');
    foreach ([12, '１２', ' 12', '12 ', '1', '123'] as $invalid) {
        $this->api('POST', $list, ['name' => 'Invalid', 'code' => $invalid], $headers)->assertUnprocessable()->assertJsonValidationErrors('code');
    }
    $this->api('PATCH', $detail, ['name' => 'Invalid', 'code' => '01'], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->api('PATCH', $detail, ['name' => 'Renamed'], $headers)->assertOk()
        ->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.code', '00');
    $this->api('DELETE', $detail, headers: $headers)->assertOk()->assertJsonPath('code', 'type_deleted');
    $this->api('GET', $detail, headers: $headers)->assertNotFound();
});

test('type deletion blocks live and trashed cases and templates without probing for unauthorized actors', function (): void {
    $case = Expedient::factory()->create();
    $template = TaskTemplate::factory()->create();
    $unused = ExpedientType::factory()->create();
    $path = '/api/v1/expedient-types/';
    $allowed = $this->authorizationBearer($this->authorizationAdmin());
    $denied = $this->authorizationBearer(User::factory()->create());

    $this->api('DELETE', $path.$unused->id, headers: $denied)->assertForbidden();
    $this->api('GET', $path.'999999', headers: $denied)->assertForbidden();
    $this->api('DELETE', $path.$case->expedient_type_id, headers: $allowed)->assertConflict();
    $case->delete();
    $this->api('DELETE', $path.$case->expedient_type_id, headers: $allowed)->assertConflict();
    $this->api('DELETE', $path.$template->expedient_type_id, headers: $allowed)->assertConflict();
    $this->assertSoftDeleted($case);
    $this->assertModelExists($template);
});
