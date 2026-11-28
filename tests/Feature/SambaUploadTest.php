<?php

use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\Incident;
use App\Models\User;
use App\Support\Samba\MountVerifier;
use App\Support\Samba\StorageConfiguration;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SambaFixture;
use Tests\Support\SambaHttp;
use Tests\Support\TestingDatabase;

uses(SambaHttp::class);

beforeEach(function (): void {
    TestingDatabase::assertSafe($this->app);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

function sambaSyntheticPdf(string $body = 'synthetic'): string
{
    return "%PDF-1.7\n% $body\n%%EOF\n";
}

test('writes verified multipart bytes once and rejects equivalent Unicode names without overwrite', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/PLANTILLAS/05', 0700, true);
        mkdir($fixture->root.'/EXPEDIENTES');
        $pdf = sambaSyntheticPdf();
        config(['samba' => ['upload_bytes' => strlen($pdf)] + $fixture->settings()]);
        $this->app->instance(MountVerifier::class, $fixture);
        $headers = $this->authorizationBearer(User::factory()->create(['locale' => 'en'])->assignRole('user'));
        $input = $this->caseInput(['expedient_type_id' => ExpedientType::factory()->create(['code' => '05'])->id]);
        $this->api('POST', '/api/v1/expedients', $input, $headers)->assertCreated()->assertJsonPath('storage.status', 'ready');
        $case = Expedient::query()->sole();
        $file = fn (string $name, string $contents): UploadedFile => UploadedFile::fake()->createWithContent($name, $contents);
        $this->sambaMultipart($case->id, ['file' => $file('Acta École.pdf', $pdf)], $headers)->assertCreated()
            ->assertExactJson(['code' => 'upload_saved', 'message' => __('samba.upload_saved'), 'case_id' => $case->id])
            ->assertHeader('Cache-Control', 'no-store, private');
        $destination = $fixture->root.'/'.$case->samba_relative_path.'/Acta École.pdf';
        expect(file_get_contents($destination))->toBe($pdf);

        $this->sambaMultipart($case->id, ['file' => $file("acta E\u{0301}COLE.PDF", $pdf)], $headers)
            ->assertConflict()->assertJsonPath('code', 'upload_name_conflict');
        $this->sambaMultipart($case->id, ['file' => $file('big.pdf', $pdf.' ')], $headers)
            ->assertStatus(413)->assertJsonPath('code', 'payload_too_large')->assertJsonValidationErrors('file');
        expect(file_get_contents($destination))->toBe($pdf)
            ->and(Incident::query()->sole()->type)->toBe('upload_name_conflict');
    } finally {
        $fixture->clean();
    }
});

test('rejects upload structure, unsafe names, forged content and failed uploads before probing storage', function (): void {
    config(['samba.uploads_per_minute' => 30]);
    $case = Expedient::factory()->create();
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $verifier = Mockery::mock(MountVerifier::class);
    $verifier->shouldNotReceive('mountId');
    $this->app->instance(MountVerifier::class, $verifier);
    $pdf = sambaSyntheticPdf();
    $file = UploadedFile::fake()->createWithContent('valid.pdf', $pdf);
    $this->api('POST', "/api/v1/expedients/{$case->id}/uploads", ['file' => 'fake'], $headers)->assertUnprocessable();
    $this->sambaMultipart($case->id, ['file' => [$file]], $headers)->assertUnprocessable();
    $this->sambaMultipart($case->id, ['file' => $file], $headers, ['path' => 'untrusted'])->assertUnprocessable();
    foreach ([
        ['../outside.pdf', $pdf], ['C:\\outside.pdf', $pdf], ['CON.pdf', $pdf],
        ['.EXPEDFLOW-ready-v1.pdf', $pdf], ['extra.pdf.', $pdf], ['x.exe', $pdf],
        ['fake.pdf', '<?php echo 1;'], ['empty.pdf', ''], ['fake.png', $pdf],
    ] as [$name, $bytes]) {
        $this->sambaMultipart($case->id, ['file' => UploadedFile::fake()->createWithContent($name, $bytes)], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }
    $this->sambaMultipart($case->id, ['file' => new UploadedFile('', 'part.pdf', error: UPLOAD_ERR_PARTIAL, test: true)], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->sambaMultipart($case->id, ['file' => new UploadedFile('', 'big.pdf', error: UPLOAD_ERR_INI_SIZE, test: true)], $headers)
        ->assertStatus(413)->assertJsonValidationErrors('file');
    $this->assertDatabaseEmpty('incidents');
});

test('requires current case access and applies the same multipart endpoint to csrf sessions and bearers', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/PLANTILLAS/05', 0700, true);
        mkdir($fixture->root.'/EXPEDIENTES');
        config(['samba' => $fixture->settings()]);
        $this->app->instance(MountVerifier::class, $fixture);
        $actor = User::factory()->create(['password' => Hash::make('synthetic test password')])->assignRole('user');
        $headers = $this->authorizationBearer($actor);
        $input = $this->caseInput(['expedient_type_id' => ExpedientType::factory()->create(['code' => '05'])->id]);
        $this->api('POST', '/api/v1/expedients', $input, $headers)->assertCreated();
        $case = Expedient::query()->sole();
        $file = ['file' => UploadedFile::fake()->createWithContent('session.pdf', sambaSyntheticPdf())];
        $this->sambaMultipart($case->id, $file, [])->assertUnauthorized();
        $denied = $this->authorizationBearer(User::factory()->create());
        $this->sambaMultipart($case->id, $file, $denied)->assertForbidden();
        $this->sambaMultipart(999999, $file, $denied)->assertForbidden();

        $this->freshCsrf();
        $this->spa('POST', '/auth/login', ['email' => $actor->email, 'password' => 'synthetic test password'])->assertOk();
        $this->sambaMultipart($case->id, $file, [], browser: true)->assertStatus(419);
        $this->sambaMultipart($case->id, $file, ['X-XSRF-TOKEN' => $this->caseBrowserCredentials()['csrf']], browser: true)->assertCreated();

        $case->delete();
        $this->sambaMultipart($case->id, $file, $headers)->assertNotFound();
        $actor->tokens()->delete();
        $this->sambaMultipart($case->id, $file, $headers)->assertUnauthorized();
        $this->assertDatabaseEmpty('incidents');
    } finally {
        $fixture->clean();
    }
});

test('reports lost mounts after an exclusive upload opens and preserves ambiguous partial bytes', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/PLANTILLAS/05', 0700, true);
        mkdir($fixture->root.'/EXPEDIENTES');
        config(['samba' => $fixture->settings()]);
        $this->app->instance(MountVerifier::class, $fixture);
        $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
        $input = $this->caseInput(['expedient_type_id' => ExpedientType::factory()->create(['code' => '05'])->id]);
        $this->api('POST', '/api/v1/expedients', $input, $headers)->assertCreated()->assertJsonPath('storage.status', 'ready');
        $case = Expedient::query()->sole();
        $destination = $fixture->root.'/'.$case->samba_relative_path.'/partial.pdf';
        $verifier = new class($fixture, $destination) implements MountVerifier
        {
            public function __construct(private readonly SambaFixture $fixture, private readonly string $destination) {}

            public function mountId(StorageConfiguration $configuration): int
            {
                clearstatcache(true, $this->destination);

                return $this->fixture->id + ((is_file($this->destination) && filesize($this->destination) > 0) ? 1 : 0);
            }
        };
        $this->app->instance(MountVerifier::class, $verifier);
        $file = UploadedFile::fake()->createWithContent('partial.pdf', sambaSyntheticPdf(str_repeat('x', 1100000)));
        $this->sambaMultipart($case->id, ['file' => $file], $headers)
            ->assertServiceUnavailable()->assertJsonPath('code', 'storage_unavailable');
        expect(filesize($destination))->toBe(1048576);

        $this->app->instance(MountVerifier::class, $fixture);
        $this->sambaMultipart($case->id, ['file' => $file], $headers)
            ->assertConflict()->assertJsonPath('code', 'upload_name_conflict');
        expect(filesize($destination))->toBe(1048576)
            ->and(Incident::query()->orderBy('id')->pluck('type')->all())->toBe(['storage_unavailable', 'upload_name_conflict']);
    } finally {
        $fixture->clean();
    }
});

test('withholds confirmation when a token is revoked during a transfer and blocks the next request', function (): void {
    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/PLANTILLAS/05', 0700, true);
        mkdir($fixture->root.'/EXPEDIENTES');
        config(['samba' => $fixture->settings()]);
        $this->app->instance(MountVerifier::class, $fixture);
        $actor = User::factory()->create()->assignRole('user');
        $headers = $this->authorizationBearer($actor);
        $input = $this->caseInput(['expedient_type_id' => ExpedientType::factory()->create(['code' => '05'])->id]);
        $this->api('POST', '/api/v1/expedients', $input, $headers)->assertCreated()->assertJsonPath('storage.status', 'ready');
        $case = Expedient::query()->sole();
        $destination = $fixture->root.'/'.$case->samba_relative_path.'/revoked.pdf';
        $verifier = new class($fixture, $destination, $actor) implements MountVerifier
        {
            private bool $revoked = false;

            public function __construct(private readonly SambaFixture $fixture, private readonly string $destination, private readonly User $actor) {}

            public function mountId(StorageConfiguration $configuration): int
            {
                clearstatcache(true, $this->destination);
                if (! $this->revoked && is_file($this->destination)) {
                    $this->revoked = true;
                    $this->actor->tokens()->delete();
                }

                return $this->fixture->id;
            }
        };
        $this->app->instance(MountVerifier::class, $verifier);
        $pdf = sambaSyntheticPdf();
        $file = UploadedFile::fake()->createWithContent('revoked.pdf', $pdf);
        $this->sambaMultipart($case->id, ['file' => $file], $headers)->assertConflict()->assertJsonPath('code', 'storage_admission_changed');
        expect(file_get_contents($destination))->toBe($pdf)
            ->and(Incident::query()->sole()->type)->toBe('storage_admission_changed');
        $this->sambaMultipart($case->id, ['file' => $file], $headers)->assertUnauthorized();
    } finally {
        $fixture->clean();
    }
});
