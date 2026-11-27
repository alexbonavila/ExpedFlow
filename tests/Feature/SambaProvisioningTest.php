<?php

use App\Models\Expedient;
use App\Models\ExpedientSequence;
use App\Models\ExpedientType;
use App\Models\Incident;
use App\Models\User;
use App\Support\Samba\MountVerifier;
use App\Support\Samba\StorageConfiguration;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\CaseHttp;
use Tests\Support\SambaFixture;
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

test('commits the case and number before the adapter sees its immutable intent', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/PLANTILLAS/05/nested/empty', 0700, true);
        mkdir($fixture->root.'/EXPEDIENTES', 0700);
        file_put_contents($fixture->root.'/PLANTILLAS/05/nested/example.txt', 'synthetic');
        config(['samba' => $fixture->settings()]);
        $observed = new class($fixture) implements MountVerifier
        {
            public bool $called = false;

            public function __construct(private readonly SambaFixture $fixture) {}

            public function mountId(StorageConfiguration $configuration): int
            {
                if (! $this->called) {
                    expect(DB::transactionLevel())->toBe(0);
                    $case = Expedient::query()->sole();
                    expect($case->samba_relative_path)->toBe('EXPEDIENTES/'.$case->code.' Prova _ còpia')
                        ->and(ExpedientSequence::query()->sole()->last_number)->toBe(1);
                    $this->called = true;
                }

                return $this->fixture->mountId($configuration);
            }
        };
        $this->app->instance(MountVerifier::class, $observed);
        $actor = User::factory()->create()->assignRole('user');
        $input = $this->caseInput(['expedient_type_id' => ExpedientType::factory()->create(['code' => '05'])->id]);
        $response = $this->api('POST', '/api/v1/expedients', ['name' => 'Prova / còpia'] + $input, $this->authorizationBearer($actor))
            ->assertCreated()->assertJsonPath('storage.status', 'ready')->assertJsonPath('storage.code', 'storage_ready')
            ->assertJsonPath('storage.incident_id', null)->assertJsonMissingPath('data.samba_provisioning');
        $case = Expedient::query()->findOrFail($response->json('data.id'));
        expect($observed->called)->toBeTrue()
            ->and(file_get_contents($fixture->root.'/'.$case->samba_relative_path.'/nested/example.txt'))->toBe('synthetic')
            ->and(is_dir($fixture->root.'/'.$case->samba_relative_path.'/nested/empty'))->toBeTrue()
            ->and($case->samba_provisioning)->toStartWith('r:');
        $this->assertDatabaseEmpty('incidents');
    } finally {
        $fixture->clean();
    }
});

test('returns 201 with a durable incident and retained annual number when Samba is unconfigured', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $response = $this->api('POST', '/api/v1/expedients', $this->caseInput(), $this->authorizationBearer($actor))
        ->assertCreated()->assertJsonPath('storage.status', 'failed')
        ->assertJsonPath('storage.code', 'storage_configuration_invalid')->assertJsonMissingPath('data.samba_relative_path');
    $case = Expedient::query()->sole();
    $incident = Incident::query()->sole();
    expect($incident->id)->toBe($response->json('storage.incident_id'))->and($incident->expedient_id)->toBe($case->id)
        ->and($incident->expedient_code)->toBe($case->code)->and($incident->user_id)->toBe($actor->id)
        ->and($incident->operation)->toBe('samba_provision')->and($incident->context)->toBeNull()
        ->and(ExpedientSequence::query()->sole()->last_number)->toBe(1);
});

test('does not probe storage or retain a number when the database creation rolls back', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $input = $this->caseInput();
    $verifier = Mockery::mock(MountVerifier::class);
    $verifier->shouldNotReceive('mountId');
    $this->app->instance(MountVerifier::class, $verifier);
    Expedient::updating(function (): never {
        throw new RuntimeException('Synthetic storage intent transaction failure.');
    });

    $this->api('POST', '/api/v1/expedients', $input, $this->authorizationBearer($actor))->assertInternalServerError();
    $this->assertDatabaseEmpty('expedients');
    $this->assertDatabaseEmpty('expedient_sequences');
    $this->assertDatabaseEmpty('incidents');
});
