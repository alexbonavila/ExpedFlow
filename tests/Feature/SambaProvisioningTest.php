<?php

use App\Models\Expedient;
use App\Models\ExpedientSequence;
use App\Models\ExpedientType;
use App\Models\Incident;
use App\Models\Task;
use App\Models\User;
use App\Support\Samba\MountVerifier;
use App\Support\Samba\StorageConfiguration;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
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
    Expedient::updating(function (Expedient $case): never {
        throw new QueryException('mysql', 'update expedients set samba_relative_path = ?',
            [$case->samba_relative_path], new RuntimeException('Synthetic storage intent transaction failure.'));
    });
    Log::shouldReceive('error')->once()->with('Case storage intent could not be persisted.');

    $this->api('POST', '/api/v1/expedients', $input, $this->authorizationBearer($actor))->assertInternalServerError()
        ->assertJsonPath('code', 'server_error')->assertJsonMissingPath('trace');
    $this->assertDatabaseEmpty('expedients');
    $this->assertDatabaseEmpty('expedient_sequences');
    $this->assertDatabaseEmpty('incidents');
});

test('retries the original target and type after reclassification and leaves ready documents untouched', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/EXPEDIENTES');
        config(['samba' => $fixture->settings()]);
        $this->app->instance(MountVerifier::class, $fixture);
        $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
        $input = $this->caseInput(['expedient_type_id' => ExpedientType::factory()->create(['code' => '05'])->id]);
        $created = $this->api('POST', '/api/v1/expedients', ['name' => 'Original'] + $input, $headers)
            ->assertCreated()->assertJsonPath('storage.code', 'storage_template_missing');
        $id = $created->json('data.id');
        $code = $created->json('data.code');
        $path = $fixture->root.'/EXPEDIENTES/'.$code.' Original';
        $nextType = ExpedientType::factory()->create(['code' => '07']);
        $this->api('PATCH', "/api/v1/expedients/$id", ['name' => 'Renamed', 'expedient_type_id' => $nextType->id], $headers)->assertOk();

        mkdir($fixture->root.'/PLANTILLAS/05/nested', 0700, true);
        file_put_contents($fixture->root.'/PLANTILLAS/05/nested/template.txt', 'original template');
        $retry = "/api/v1/expedients/$id/storage/retry";
        $this->api('POST', $retry, [], $headers)->assertOk()->assertJsonPath('code', 'storage_ready');
        file_put_contents($path.'/nested/template.txt', 'operator updated');
        $this->api('POST', $retry, [], $headers)->assertOk()->assertJsonPath('status', 'ready');
        expect(file_get_contents($path.'/nested/template.txt'))->toBe('operator updated')
            ->and(scandir($fixture->root.'/EXPEDIENTES'))->toBe(['.', '..', $code.' Original'])
            ->and(ExpedientSequence::query()->sole()->last_number)->toBe(1)
            ->and(Incident::query()->count())->toBe(1);

        rename($path, $path.'-offline');
        $this->api('POST', $retry, [], $headers)->assertConflict()->assertJsonPath('code', 'storage_not_ready');
        expect(is_dir($path))->toBeFalse();
    } finally {
        $fixture->clean();
    }
});

test('resumes a matching owned partial tree and rejects a changed revision or malformed marker', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/EXPEDIENTES');
        mkdir($fixture->root.'/PLANTILLAS/05', 0700, true);
        $source = $fixture->root.'/PLANTILLAS/05';
        file_put_contents($source.'/a.txt', 'complete');
        file_put_contents($source.'/b.txt', 'missing');
        config(['samba' => $fixture->settings()]);
        $this->app->instance(MountVerifier::class, $fixture);
        $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
        $input = $this->caseInput(['expedient_type_id' => ExpedientType::factory()->create(['code' => '05'])->id]);
        $this->api('POST', '/api/v1/expedients', $input, $headers)->assertCreated()->assertJsonPath('storage.status', 'ready');
        $case = Expedient::query()->sole();
        $target = $fixture->root.'/'.$case->samba_relative_path;
        $retry = "/api/v1/expedients/{$case->id}/storage/retry";

        unlink($target.'/.expedflow-ready-v1');
        unlink($target.'/b.txt');
        $case->forceFill(['samba_provisioning' => 'p:'.substr($case->samba_provisioning, 2)])->save();
        file_put_contents($source.'/b.txt', 'new revision');
        $this->api('POST', $retry, [], $headers)->assertConflict()->assertJsonPath('code', 'storage_conflict');
        expect(is_file($target.'/b.txt'))->toBeFalse();
        file_put_contents($source.'/b.txt', 'missing');
        file_put_contents($target.'/b.txt', 'partial');
        $this->api('POST', $retry, [], $headers)->assertConflict();
        expect(file_get_contents($target.'/b.txt'))->toBe('partial');
        unlink($target.'/b.txt');
        $this->api('POST', $retry, [], $headers)->assertOk();
        expect(file_get_contents($target.'/b.txt'))->toBe('missing');

        file_put_contents($target.'/.expedflow-ready-v1', str_repeat('a', 64));
        $this->api('POST', $retry, [], $headers)->assertConflict();
        expect(file_get_contents($target.'/.expedflow-ready-v1'))->toBe(str_repeat('a', 64));
    } finally {
        $fixture->clean();
    }
});

test('admits legacy retry explicitly and rejects unowned folders and inactive or unauthorized cases', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/EXPEDIENTES');
        config(['samba' => $fixture->settings()]);
        $this->app->instance(MountVerifier::class, $fixture);
        $case = Expedient::factory()->create(['name' => 'Legacy case']);
        $actor = User::factory()->create()->assignRole('user');
        $allowed = $this->authorizationBearer($actor);
        $denied = $this->authorizationBearer(User::factory()->create());
        $retry = "/api/v1/expedients/{$case->id}/storage/retry";
        $this->api('POST', $retry, [], $denied)->assertForbidden();
        $this->api('POST', '/api/v1/expedients/999999/storage/retry', [], $denied)->assertForbidden();
        $this->api('POST', $retry, ['path' => 'private'], $allowed)->assertUnprocessable()->assertJsonValidationErrors('request');
        expect($case->fresh()->samba_relative_path)->toBeNull();
        $target = $fixture->root.'/EXPEDIENTES/'.$case->code.' Legacy case';
        mkdir($target);
        $this->api('POST', $retry, [], $allowed)->assertConflict()->assertJsonPath('code', 'storage_conflict');
        expect(scandir($target))->toBe(['.', '..'])
            ->and($case->fresh()->samba_relative_path)->toBe('EXPEDIENTES/'.$case->code.' Legacy case');
        $case->delete();
        $this->api('POST', $retry, [], $allowed)->assertNotFound();
        expect(Incident::query()->count())->toBe(1);
    } finally {
        $fixture->clean();
    }
});

test('refuses a simultaneous retry while an independent PHP process holds the case lock', function (): void {
    $case = Expedient::factory()->create();
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $retry = "/api/v1/expedients/{$case->id}/storage/retry";
    config(['samba.mountpoint' => null]);

    $input = new InputStream;
    $holder = new Process([PHP_BINARY, 'tests/Fixtures/SambaLockHolder.php', (string) $case->id], base_path(), ['APP_ENV' => 'testing']);
    try {
        $holder->setInput($input)->setTimeout(8)->start();
        $stdout = '';
        $ready = $holder->waitUntil(function (string $type, string $output) use (&$stdout): bool {
            if ($type === Process::OUT) {
                $stdout .= $output;
            }

            return str_contains($stdout, "locked\n");
        });
        expect($ready)->toBeTrue('Lock holder exited before acquiring the lock. '.$holder->getErrorOutput());

        $attempt = $this->api('POST', $retry, [], $headers);
        expect($holder->isRunning())->toBeTrue('Retry returned after the lock holder exited. '.$holder->getErrorOutput());
        $attempt->assertConflict()->assertJsonPath('code', 'storage_busy')->assertJsonPath('incident_id', null);
        $this->assertDatabaseEmpty('incidents');
        expect($case->fresh()->samba_relative_path)->toBeNull();

        $input->write("release\n");
        $input->close();
        expect($holder->wait())->toBe(0, $holder->getErrorOutput());
    } finally {
        $input->close();
        $holder->stop(0.25);
    }

    expect($holder->isRunning())->toBeFalse();
    $this->api('POST', $retry, [], $headers)
        ->assertServiceUnavailable()->assertJsonPath('code', 'storage_configuration_invalid');
})->group('samba-lock');

test('preserves copied task rows and storage across retry, trash, restore and force delete', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/EXPEDIENTES');
        config(['samba' => $fixture->settings()]);
        $this->app->instance(MountVerifier::class, $fixture);
        Expedient::created(function (Expedient $case): void {
            // Mirrors the sibling writer's transactional task copy at the shared case boundary.
            expect(DB::transactionLevel())->toBeGreaterThan(0);
            Task::factory()->for($case)->create();
        });
        $admin = $this->authorizationAdmin();
        $headers = $this->authorizationBearer($admin);
        $input = $this->caseInput(['expedient_type_id' => ExpedientType::factory()->create(['code' => '05'])->id]);
        $this->api('POST', '/api/v1/expedients', $input, $headers)->assertCreated()->assertJsonPath('storage.status', 'failed');
        $case = Expedient::query()->sole();
        $task = $case->tasks()->sole();
        mkdir($fixture->root.'/PLANTILLAS/05', 0700, true);
        file_put_contents($fixture->root.'/PLANTILLAS/05/synthetic.txt', 'survives lifecycle');
        $path = "/api/v1/expedients/{$case->id}";
        $this->api('POST', "$path/storage/retry", [], $headers)->assertOk();
        expect($case->tasks()->sole()->id)->toBe($task->id);
        $destination = $fixture->root.'/'.$case->samba_relative_path.'/synthetic.txt';

        $this->api('DELETE', $path, [], $headers)->assertOk();
        expect(file_get_contents($destination))->toBe('survives lifecycle');
        $this->api('POST', "$path/restore", [], $headers)->assertOk();
        expect(file_get_contents($destination))->toBe('survives lifecycle');
        $this->api('DELETE', $path, [], $headers)->assertOk();
        $this->api('POST', "$path/force-delete", [], $headers)->assertOk();
        expect(file_get_contents($destination))->toBe('survives lifecycle')
            ->and(Incident::query()->sole()->expedient_id)->toBeNull()
            ->and(Incident::query()->sole()->expedient_code)->toBe($case->code);
    } finally {
        $fixture->clean();
    }
});

test('upgrades and rolls back only the provisioning state on an existing case', function (): void {
    $migration = require database_path('migrations/2026_11_27_120000_add_case_samba_provisioning.php');
    $case = Expedient::factory()->create([
        'samba_relative_path' => 'EXPEDIENTES/legacy reference',
        'samba_provisioning' => 'r:'.str_repeat('a', 64),
    ]);
    $migration->down();
    try {
        expect(Schema::hasColumn('expedients', 'samba_provisioning'))->toBeFalse();
        expect($case->fresh()->samba_relative_path)->toBe('EXPEDIENTES/legacy reference');
    } finally {
        $migration->up();
    }
    expect($case->fresh()->samba_provisioning)->toBeNull()
        ->and($case->fresh()->samba_relative_path)->toBe('EXPEDIENTES/legacy reference');
});
