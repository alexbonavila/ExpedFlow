<?php

use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Incident;
use App\Models\User;
use App\Support\Samba\LinuxMountVerifier;
use App\Support\Samba\MountVerifier;
use App\Support\Samba\StorageConfiguration;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Http\UploadedFile;
use Tests\Support\SambaHttp;
use Tests\Support\TestingDatabase;
use Tests\TestCase;

uses(TestCase::class, SambaHttp::class);

test('exercises the production adapter against the disposable CIFS fixture', function (): void {
    TestingDatabase::assertSafe($this->app);
    $this->identityHttp();
    $stage = getenv('SAMBA_CIFS_STAGE');
    $root = getenv('SAMBA_CIFS_MOUNT');
    expect($stage)->toBeIn(['writer', 'reader', 'readonly', 'wrong-source', 'missing', 'loss', 'reconnect'])
        ->and($root)->toBeString()->toMatch('~^/tmp/expedflow-cifs\.[A-Za-z0-9]+/mounted$~D');
    config([
        'samba.mountpoint' => $root,
        'samba.source' => $stage === 'wrong-source' ? '//127.0.0.1/wrong-fixture' : '//127.0.0.1/fixtures',
    ]);
    $pdf = "%PDF-1.7\n% CIFS fixture\n%%EOF\n";
    $filename = 'Acta de reunió.pdf';
    $partialName = 'interrupted.pdf';

    if ($stage === 'writer') {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create(['locale' => 'en'])->assignRole('user');
        $headers = $this->authorizationBearer($user);
        $input = $this->caseInput(['expedient_type_id' => ExpedientType::factory()->create(['code' => '05'])->id]);
        $this->api('POST', '/api/v1/expedients', ['name' => 'CIFS Àrea'] + $input, $headers)
            ->assertCreated()->assertJsonPath('storage.status', 'ready')->assertJsonMissingPath('data.samba_relative_path');

        $case = Expedient::query()->sole();
        $base = $root.'/EXPEDIENTES/'.$case->code.' CIFS Àrea';
        expect(hash_file('sha256', $base.'/nested/fixture.txt'))->toBe(hash('sha256', "Synthetic template file\n"))
            ->and(is_dir($base.'/nested/empty'))->toBeTrue();
        $file = UploadedFile::fake()->createWithContent($filename, $pdf);
        $this->sambaMultipart($case->id, ['file' => $file], $headers)->assertCreated()->assertJsonPath('code', 'upload_saved');
        $this->sambaMultipart($case->id, ['file' => $file], $headers)->assertConflict()->assertJsonPath('code', 'upload_name_conflict');
        expect(hash_file('sha256', $base.'/'.$filename))->toBe(hash('sha256', $pdf));
        $this->api('POST', "/api/v1/expedients/{$case->id}/storage/retry", [], $headers)->assertOk();
        expect(hash_file('sha256', $base.'/'.$filename))->toBe(hash('sha256', $pdf));

        return;
    }

    $case = Expedient::query()->sole();
    $headers = $this->authorizationBearer(User::query()->sole());
    $retry = "/api/v1/expedients/{$case->id}/storage/retry";
    $base = $root.'/EXPEDIENTES/'.$case->code.' CIFS Àrea';

    if ($stage === 'missing') {
        expect(is_writable($root))->toBeFalse()
            ->and(@mkdir($root.'/local-fallback-probe'))->toBeFalse()
            ->and(scandir($root))->toBe(['.', '..']);
    }

    if ($stage === 'loss') {
        $signal = dirname($root);
        $this->app->instance(MountVerifier::class, new class($base.'/'.$partialName, $signal) implements MountVerifier
        {
            public function __construct(private readonly string $destination, private readonly string $signal) {}

            public function mountId(StorageConfiguration $configuration): int
            {
                clearstatcache(true);
                if (is_file($this->destination) && filesize($this->destination) > 0) {
                    if (! touch($this->signal.'/transfer-started')) {
                        throw new RuntimeException('Could not signal the isolated transfer stage.');
                    }
                    for ($attempt = 0; $attempt < 80; $attempt++) {
                        clearstatcache(true);
                        if (is_file($this->signal.'/fixture-detached')) {
                            break;
                        }
                        usleep(250_000);
                    }
                }

                return (new LinuxMountVerifier)->mountId($configuration);
            }
        });
        $large = UploadedFile::fake()->createWithContent($partialName, "%PDF-1.7\n% ".str_repeat('f', 2_200_000)."\n%%EOF\n");
        $lost = $this->sambaMultipart($case->id, ['file' => $large], $headers)
            ->assertServiceUnavailable()->assertJsonPath('code', 'storage_unavailable');
        $this->assertDatabaseHas('incidents', ['id' => $lost->json('incident_id'), 'expedient_code' => $case->code]);
        expect(scandir($root))->toBe(['.', '..']);

        return;
    }

    if ($stage === 'reconnect') {
        $this->api('POST', $retry, [], $headers)->assertOk()->assertJsonPath('status', 'ready');
        expect(hash_file('sha256', $base.'/'.$filename))->toBe(hash('sha256', $pdf));
        $partial = @filesize($base.'/'.$partialName);
        expect($partial)->toBe(1_048_576);
        $this->sambaMultipart($case->id, ['file' => UploadedFile::fake()->createWithContent($partialName, $pdf)], $headers)
            ->assertConflict()->assertJsonPath('code', 'upload_name_conflict');
        expect(@filesize($base.'/'.$partialName))->toBe($partial);
        $this->sambaMultipart($case->id, ['file' => UploadedFile::fake()->createWithContent('reconnected.pdf', $pdf)], $headers)->assertCreated();
        expect(hash_file('sha256', $base.'/reconnected.pdf'))->toBe(hash('sha256', $pdf))
            ->and(Incident::query()->where('type', 'storage_unavailable')->exists())->toBeTrue();

        return;
    }

    $file = UploadedFile::fake()->createWithContent('unwritten.pdf', $pdf);
    $this->sambaMultipart($case->id, ['file' => $file], $headers)->assertServiceUnavailable()
        ->assertJsonPath('code', $stage === 'reader' ? 'storage_transfer_failed' : 'storage_unavailable');
    if ($stage === 'missing') {
        expect(scandir($root))->toBe(['.', '..']);
    } else {
        expect(is_file($base.'/unwritten.pdf'))->toBeFalse()
            ->and(hash_file('sha256', $base.'/'.$filename))->toBe(hash('sha256', $pdf));
    }
});
