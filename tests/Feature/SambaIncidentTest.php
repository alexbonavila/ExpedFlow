<?php

use App\Models\Expedient;
use App\Models\Incident;
use App\Models\User;
use App\Support\PermissionKey;
use App\Support\Samba\Failure;
use App\Support\Samba\IncidentRecorder;
use App\Support\Samba\Operation;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Support\CaseHttp;
use Tests\Support\RefreshGuardedMySql;

uses(CaseHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('authorizes incident reads before lookup and rejects write routes', function (): void {
    $record = Incident::factory()->create();
    $denied = $this->authorizationBearer(User::factory()->create());
    $reader = User::factory()->create();
    $reader->assignRole(Role::create(['name' => 'incident reader'])->givePermissionTo(PermissionKey::ViewIncidents->value));
    $allowed = $this->authorizationBearer($reader);
    $this->api('GET', '/api/v1/incidents')->assertUnauthorized();
    $this->api('GET', '/api/v1/incidents', [], $denied)->assertForbidden();
    foreach ([$record->id, 999999] as $id) {
        $this->api('GET', "/api/v1/incidents/$id", [], $denied)->assertForbidden();
    }
    $this->api('GET', '/api/v1/incidents/999999', [], $allowed)->assertNotFound();
    $this->api('POST', '/api/v1/incidents', [], $allowed)->assertMethodNotAllowed();
    $this->api('DELETE', "/api/v1/incidents/{$record->id}", [], $allowed)->assertMethodNotAllowed();
});

test('projects safe incident fields, uses deterministic ordering and validates bounded filters', function (): void {
    $actor = User::factory()->create();
    $case = Expedient::factory()->create(['name' => 'Private case name']);
    $time = CarbonImmutable::parse('2027-02-04 13:02:01 UTC')->setTimezone(date_default_timezone_get());
    $new = Incident::factory()->for($actor)->for($case)->create([
        'expedient_code' => $case->code,
        'operation' => Operation::Upload->value,
        'type' => Failure::Transfer->value,
        'message' => 'redact unchecked fixture filename',
        'context' => ['path' => '/private/secret.pdf'],
        'created_at' => $time,
    ]);
    $legacy = Incident::factory()->create([
        'operation' => 'legacy technical detail', 'type' => 'legacy secret', 'message' => 'raw legacy value',
        'context' => ['secret' => 'untrusted'], 'created_at' => $time,
    ]);
    $old = Incident::factory()->create(['created_at' => $time->subDay()]);
    $headers = $this->authorizationBearer($this->authorizationAdmin('ca'));
    $result = $this->api('GET', '/api/v1/incidents', [], $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    expect(array_column($result->json('data'), 'id'))->toBe([$legacy->id, $new->id, $old->id]);
    $result->assertJsonPath('data.0.operation', 'unknown')->assertJsonPath('data.0.type', 'unknown')
        ->assertJsonPath('data.0.message', __('samba.incident_unknown', locale: 'ca'))
        ->assertDontSee('raw legacy value')->assertDontSee('legacy secret')->assertDontSee('redact unchecked')
        ->assertDontSee('secret.pdf')->assertDontSee($actor->email)->assertDontSee($case->name);
    $this->api('GET', "/api/v1/incidents/{$new->id}", [], $headers)->assertOk()->assertExactJson(['data' => [
        'id' => $new->id, 'case_id' => $case->id, 'case_code' => $case->code, 'actor_id' => $actor->id,
        'operation' => 'samba_upload', 'type' => 'storage_transfer_failed', 'created_at' => $time->toISOString(),
        'message' => __('samba.storage_transfer_failed', locale: 'ca'),
    ]]);
    $this->api('GET', "/api/v1/incidents?case_code={$case->code}&operation=samba_upload&type=storage_transfer_failed", [], $headers)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $new->id);
    $this->api('GET', '/api/v1/incidents?case_code=wrong&operation=invalid&type=unknown', [], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors(['case_code', 'operation', 'type']);
});

test('preserves case snapshots after physical deletion without storing unchecked context or sending notifications', function (): void {
    Mail::fake();
    Notification::fake();
    $actor = User::factory()->create();
    $case = Expedient::factory()->create();
    $case->forceDelete();
    $actor->delete();
    $id = app(IncidentRecorder::class)->record(Operation::Retry, Failure::Unavailable, $case, $actor->id);

    $headers = $this->authorizationBearer($this->authorizationAdmin('es'));
    $this->api('GET', "/api/v1/incidents/$id", [], $headers)->assertOk()
        ->assertJsonPath('data.case_code', $case->code)->assertJsonPath('data.case_id', null)
        ->assertJsonPath('data.actor_id', null)->assertJsonPath('data.message', __('samba.storage_unavailable', locale: 'es'));
    expect(Incident::query()->sole()->context)->toBeNull();
    Mail::assertNothingOutgoing();
    Notification::assertNothingSent();
});

test('reports a failed incident insert with sanitized logging and no reference', function (): void {
    $case = Expedient::factory()->create(['samba_relative_path' => 'private']);
    Incident::creating(function (): never {
        throw new RuntimeException('Synthetic sensitive database exception.');
    });
    Log::shouldReceive('error')->once()->with('Case storage incident could not be persisted.', [
        'operation' => 'samba_provision', 'reason' => 'storage_unavailable',
    ]);
    expect(app(IncidentRecorder::class)->record(Operation::Provision, Failure::Unavailable, $case, null))->toBeNull();
    $this->assertDatabaseEmpty('incidents');
});
