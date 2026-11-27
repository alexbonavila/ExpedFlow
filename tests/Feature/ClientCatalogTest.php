<?php

use App\Models\Client;
use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Status;
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

test('client writes normalize Unicode boundaries and enforce case and accent uniqueness', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $first = $this->api('POST', '/api/v1/clients', ['name' => "\u{00a0}Café  Projects\u{2003}", 'id' => 9999], $headers)
        ->assertCreated()->assertJsonPath('data.name', 'Café  Projects')->assertJsonMissingPath('data.email');
    $id = $first->json('data.id');
    expect($id)->not->toBe(9999);
    $go = "/api/v1/clients/$id";
    $this->api('GET', $go, headers: $headers)->assertOk()->assertExactJson($first->json());
    $this->api('POST', '/api/v1/clients', ['name' => 'CAFE  PROJECTS'], $headers)->assertConflict()
        ->assertJsonPath('code', 'client_name_conflict');
    $this->api('PATCH', $go, ['name' => 'CAFE  Projects'], $headers)->assertOk()
        ->assertJsonPath('data.name', 'CAFE  Projects');
    $second = Client::factory()->create(['name' => 'Another client']);
    $this->api('PATCH', $go, ['name' => $second->name], $headers)->assertConflict();
    $this->api('GET', '/api/v1/clients', headers: $headers)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $second->id)->assertJsonMissingPath('links')
        ->assertHeader('Cache-Control', 'no-store, private');
    foreach ([null, 1, [], "\u{00a0}\u{2003}", str_repeat('é', 256)] as $invalid) {
        $this->api('POST', '/api/v1/clients', ['name' => $invalid], $headers)->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }
    $this->assertDatabaseHas('clients', ['id' => $id, 'name' => 'CAFE  Projects']);
});

test('client removal protects requester and represented references including trash', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $represented = Client::factory()->create();
    $case = Expedient::factory()->create(['represented_client_id' => $represented->id]);
    $unused = Client::factory()->create();

    foreach ([$case->requester_client_id, $represented->id] as $id) {
        $this->api('DELETE', "/api/v1/clients/$id", headers: $headers)->assertConflict()->assertJsonPath('code', 'client_referenced');
    }
    $case->delete();
    foreach ([$case->requester_client_id, $represented->id] as $id) {
        $this->api('DELETE', "/api/v1/clients/$id", headers: $headers)->assertConflict()->assertJsonPath('code', 'client_referenced');
    }
    $this->api('DELETE', "/api/v1/clients/{$unused->id}", headers: $headers)->assertOk()->assertJsonPath('code', 'client_deleted');
    $this->api('DELETE', "/api/v1/clients/{$unused->id}", headers: $headers)->assertNotFound();
    $this->assertSoftDeleted($case);
    $this->assertModelExists($represented);
});

test('read and write permissions differ and catalog lookups authorize before disclosure', function (): void {
    $role = Role::query()->create(['name' => 'Client writer', 'guard_name' => 'web']);
    $role->givePermissionTo('clients.manage');
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole($role));
    $this->api('GET', '/api/v1/clients')->assertUnauthorized();
    $this->api('GET', '/api/v1/clients/987654', headers: $headers)->assertForbidden();
    $this->api('POST', '/api/v1/clients', ['name' => 'Managed client'], $headers)->assertCreated();
    $this->api('GET', '/api/v1/expedient-types', headers: $headers)->assertForbidden();
    $this->api('GET', '/api/v1/statuses', headers: $headers)->assertForbidden();
});

test('lookup lists preserve leading zeros and localize statuses with fallback and literal custom names', function (
    string $locale, string $translated, string $fallback,
): void {
    $headers = $this->authorizationBearer(User::factory()->create(['locale' => $locale])->assignRole('user'));
    ExpedientType::factory()->create(['code' => '12']);
    $type = ExpedientType::factory()->create(['code' => '05']);
    Status::factory()->create(['name' => 'Literal shared label', 'position' => 3]);
    Status::factory()->create(['name' => 'Primera', 'position' => 1,
        'name_translations' => ['es' => 'Primera', 'ca' => 'Primera CA', 'en' => 'First']]);
    Status::factory()->create(['name' => 'Literal fallback', 'position' => 2, 'name_translations' => ['en' => 'Fallback EN']]);

    $this->api('GET', '/api/v1/expedient-types', headers: $headers)->assertOk()
        ->assertJsonPath('data.0.id', $type->id)->assertJsonPath('data.0.code', '05');
    $this->api('GET', '/api/v1/statuses', headers: $headers)->assertOk()
        ->assertJsonPath('data.0.name', $translated)->assertJsonPath('data.1.name', $fallback)
        ->assertJsonPath('data.2.name', 'Literal shared label')->assertJsonMissingPath('data.0.name_translations');
    $this->api('POST', '/api/v1/statuses', ['name' => 'Unimplemented'], $headers)->assertMethodNotAllowed();
})->with([['ca', 'Primera CA', 'Fallback EN'], ['en', 'First', 'Fallback EN'], ['es', 'Primera', 'Fallback EN']]);
