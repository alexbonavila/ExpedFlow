<?php

use App\Models\Client;
use App\Models\Expedient;
use App\Models\ExpedientSequence;
use App\Models\ExpedientType;
use App\Models\ExpedientUserPreference;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\CaseHttp;
use Tests\Support\RefreshGuardedMySql;

uses(CaseHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('case creation reserves one shared annual sequence in the installation time zone and keeps an immutable code', function (): void {
    config(['app.timezone' => 'Europe/Madrid']);
    $this->travelTo(CarbonImmutable::parse('2036-12-31 22:59 UTC'));
    $actor = User::factory()->create()->assignRole('user');
    $headers = $this->authorizationBearer($actor);
    $type = ExpedientType::factory()->create(['code' => '05']);
    $alternate = ExpedientType::factory()->create(['code' => '09']);
    $input = $this->caseInput(['expedient_type_id' => $type->id]);
    $represented = Client::factory()->create();

    $first = $this->api('POST', '/api/v1/expedients', ['name' => " \u{2003}Case  A\u{00A0}",
        'represented_client_id' => $represented->id, 'id' => 9999, 'code' => '200000100', 'year' => 2000,
        'sequence_number' => 999, 'column_position' => 200, 'column_version' => 90,
        'samba_relative_path' => 'fake/input', 'created_at' => '2001-01-01', 'deleted_at' => '2036-01-01',
    ] + $input, $headers)->assertCreated()
        ->assertJsonPath('data.code', '203600105')->assertJsonPath('data.type.code', '05')
        ->assertJsonPath('data.name', 'Case  A')->assertJsonPath('data.column_position', 1)
        ->assertJsonPath('data.column_version', 1)->assertJsonPath('data.represented_client.id', $represented->id)
        ->assertJsonMissingPath('data.samba_relative_path')->assertJsonMissingPath('data.deleted_at');
    $id = $first->json('data.id');
    expect($id)->not->toBe(9999);
    $case = Expedient::query()->findOrFail($id);
    expect($case->samba_relative_path)->toBeNull()->and($case->year)->toBe(2036)
        ->and($case->sequence_number)->toBe(1);

    $this->api('POST', '/api/v1/expedients', ['expedient_type_id' => $alternate->id] + $input, $headers)->assertCreated()
        ->assertJsonPath('data.code', '203600209')->assertJsonPath('data.column_position', 2);
    $this->travelTo(CarbonImmutable::parse('2036-12-31 23:01 UTC'));
    $this->api('POST', '/api/v1/expedients', $input, $headers)->assertCreated()->assertJsonPath('data.code', '203700105');
    $this->api('PATCH', "/api/v1/expedients/$id", [
        'expedient_type_id' => $alternate->id, 'description' => null, 'represented_client_id' => null,
        'code' => '203699909', 'sequence_number' => 999, 'year' => 2037,
    ], $headers)->assertOk()->assertJsonPath('data.code', '203600105')->assertJsonPath('data.type.code', '09')
        ->assertJsonPath('data.description', null)->assertJsonPath('data.represented_client', null);
    expect(ExpedientSequence::query()->orderBy('year')->pluck('last_number', 'year')->all())->toBe([2036 => 2, 2037 => 1]);
});

test('case writes reject missing references, unsafe payloads and invalid client pairs before committing', function (): void {
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2033-04-01 UTC'));
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $input = $this->caseInput();
    $this->api('POST', '/api/v1/expedients', [], $headers)->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'status_id', 'requester_client_id', 'expedient_type_id']);
    foreach ([
        'name' => "\u{00A0} ",
        'status_id' => (string) $input['status_id'],
        'expedient_type_id' => 90000,
        'requester_client_id' => 90000,
        'represented_client_id' => $input['requester_client_id'],
        'description' => str_repeat('á', 32768),
        'template_id' => 1,
        'tag_ids' => [],
    ] as $field => $value) {
        $this->api('POST', '/api/v1/expedients', [$field => $value] + $input, $headers)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    foreach (['status_id', 'represented_client_id'] as $field) {
        $this->api('POST', '/api/v1/expedients', [$field => 90000] + $input, $headers)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->assertDatabaseEmpty('expedient_sequences');
    $represented = Client::factory()->create();
    $created = $this->api('POST', '/api/v1/expedients', ['represented_client_id' => $represented->id,
        'description' => str_repeat('á', 32767).'a'] + $input, $headers)->assertCreated();
    $id = $created->json('data.id');
    $this->api('PATCH', "/api/v1/expedients/$id", ['requester_client_id' => $represented->id], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('represented_client_id');
    $this->assertDatabaseHas('expedients', ['id' => $id, 'requester_client_id' => $input['requester_client_id']]);
    expect(ExpedientSequence::query()->findOrFail(2033)->last_number)->toBe(1);
});

test('numbering refuses a corrupt sequence and rolls back a failed case insertion', function (): void {
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2034-06-01 UTC'));
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $input = $this->caseInput();
    ExpedientSequence::query()->forceCreate(['year' => 2035, 'last_number' => 6]);
    ExpedientSequence::query()->forceCreate(['year' => 2034, 'last_number' => 4]);
    $case = Expedient::factory()->create(['year' => 2034, 'sequence_number' => 8, 'code' => '203400805'] + $input);
    $this->api('POST', '/api/v1/expedients', $input, $headers)->assertConflict()->assertJsonPath('code', 'annual_sequence_inconsistent');
    $this->assertModelExists($case);

    $this->travelTo(CarbonImmutable::parse('2035-06-01 UTC'));
    Expedient::creating(function (): never {
        throw new RuntimeException('Synthetic case insertion failure.');
    });
    $this->api('POST', '/api/v1/expedients', $input, $headers)->assertInternalServerError()
        ->assertJsonPath('code', 'server_error')->assertJsonMissingPath('trace');
    $this->assertDatabaseMissing('expedients', ['year' => 2035]);
    expect(ExpedientSequence::query()->findOrFail(2035)->last_number)->toBe(6);
});

test('status changes append at the destination and invalidate position versions while retaining visibility and task state', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $headers = $this->authorizationBearer($actor);
    $case = Expedient::factory()->create(['column_position' => 15, 'column_version' => 4]);
    $destination = Status::factory()->create();
    $tail = Expedient::factory()->create(['status_id' => $destination->id, 'column_position' => 90]);
    $tail->delete();
    $task = Task::factory()->for($case)->create(['position' => 30]);
    $preference = ExpedientUserPreference::factory()->for($case)->for($actor)->create([
        'hidden' => true, 'position' => 11, 'column_version' => 4,
    ]);

    $this->api('PATCH', "/api/v1/expedients/{$case->id}", ['status_id' => $destination->id], $headers)
        ->assertOk()->assertJsonPath('data.column_position', 91)->assertJsonPath('data.column_version', 5);
    $this->api('PATCH', "/api/v1/expedients/{$case->id}", ['status_id' => $destination->id, 'name' => 'Same column'], $headers)
        ->assertOk()->assertJsonPath('data.column_position', 91)->assertJsonPath('data.column_version', 5);
    expect($preference->refresh()->hidden)->toBeTrue()->and($preference->position)->toBe(11)
        ->and($preference->column_version)->toBe(4);
    expect($task->fresh()->status_id)->toBe($task->status_id)->and($task->fresh()->position)->toBe(30);
    $case->refresh()->forceFill(['column_version' => PHP_INT_MAX])->save();
    $this->api('PATCH', "/api/v1/expedients/{$case->id}", ['status_id' => $case->status_id + 99999], $headers)->assertUnprocessable();
    $this->api('PATCH', "/api/v1/expedients/{$case->id}", ['status_id' => Status::factory()->create()->id], $headers)
        ->assertConflict()->assertJsonPath('code', 'case_order_exhausted');
    expect($case->fresh()->status_id)->toBe($destination->id);
});

test('global case lists support literal search, filters and deterministic order with bounded relation queries', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $headers = $this->authorizationBearer($actor);
    $status = Status::factory()->create();
    $type = ExpedientType::factory()->create(['code' => '05']);
    $client = Client::factory()->create();
    $represented = Client::factory()->create();
    $input = ['name' => 'Café 100%_!', 'status_id' => $status->id, 'expedient_type_id' => $type->id,
        'requester_client_id' => $client->id, 'represented_client_id' => $represented->id];
    $cases = Expedient::factory()->count(18)->create($input);
    ExpedientUserPreference::factory()->for($cases->first())->for($actor)->create(['hidden' => true]);
    Expedient::factory()->create(['name' => 'Café 100XY!'] + $input);
    Expedient::factory()->create($input)->delete();

    $query = http_build_query(['q' => '%_!', 'sort' => 'name', 'direction' => 'desc',
        'status_id' => $status->id, 'expedient_type_id' => $type->id,
        'requester_client_id' => $client->id, 'represented_client_id' => $represented->id]);
    DB::enableQueryLog();
    try {
        $response = $this->api('GET', "/api/v1/expedients?$query", headers: $headers)->assertOk()
            ->assertJsonCount(18, 'data')->assertJsonMissingPath('meta')->assertJsonMissingPath('links');
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }
    expect(array_column($response->json('data'), 'id'))->toBe($cases->modelKeys());
    foreach (['expedient_types', 'statuses', 'clients'] as $relation) {
        $relationQueries = array_filter($queries, fn (array $query): bool => str_contains($query['query'], "from `$relation`"));
        expect(count($relationQueries))->toBe($relation === 'clients' ? 2 : 1);
    }
    $this->api('GET', '/api/v1/expedients?sort=deleted_at&direction=sideways&status_id=1.5', headers: $headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['sort', 'direction', 'status_id']);
});

test('case action permissions are independent and checked before any record disclosure', function (): void {
    $writer = Role::query()->create(['name' => 'Case writer', 'guard_name' => 'web']);
    $writer->givePermissionTo(['expedients.create', 'expedients.update']);
    $actor = User::factory()->create()->assignRole($writer);
    $headers = $this->authorizationBearer($actor);
    $this->api('GET', '/api/v1/expedients')->assertUnauthorized();
    $created = $this->api('POST', '/api/v1/expedients', $this->caseInput(), $headers)->assertCreated();
    $path = '/api/v1/expedients/'.$created->json('data.id');
    $this->api('PATCH', $path, ['name' => 'Updated without read grant'], $headers)->assertOk();
    foreach ([$path, '/api/v1/expedients/999999', '/api/v1/expedients/trash/999999'] as $resource) {
        $this->api('GET', $resource, headers: $headers)->assertForbidden();
    }
    $this->api('DELETE', '/api/v1/expedients/999999', headers: $headers)->assertForbidden();
    $this->api('DELETE', '/api/v1/auth/token', headers: $headers)->assertOk();
    $this->api('PATCH', $path, ['name' => 'Revoked'], $headers)->assertUnauthorized();
    $disabled = User::factory()->create(['disabled_at' => now()])->assignRole('user');
    $this->api('GET', '/api/v1/expedients', headers: $this->authorizationBearer($disabled))->assertUnauthorized();
});

test('case writes accept a genuine database SPA session and require CSRF', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $actor->email, 'password' => 'password'])->assertOk();
    $input = $this->caseInput();
    $this->api('POST', '/api/v1/expedients', $input, browser: true)->assertStatus(419);
    $this->spa('POST', '/expedients', $input)->assertCreated();
    $this->spa('POST', '/auth/logout')->assertOk();
    $this->spa('POST', '/expedients', $input)->assertUnauthorized();
});
