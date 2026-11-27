<?php

use App\Models\Expedient;
use App\Models\ExpedientType;
use App\Models\User;
use App\Support\Samba\MountVerifier;
use cebe\openapi\Reader;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile as PsrUploadedFile;
use Tests\Support\SambaFixture;
use Tests\Support\SambaHttp;
use Tests\Support\TestingDatabase;

uses(SambaHttp::class);

afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

test('Phase I OpenAPI validates case storage, multipart upload and safe incident responses', function (): void {
    TestingDatabase::assertSafe($this->app);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
    $path = base_path('tests/Fixtures/phase-i-openapi.json');
    $document = Reader::readFromJsonFile($path);
    $this->assertTrue($document->validate(), implode(PHP_EOL, $document->getErrors()));
    $spec = (new ValidatorBuilder)->fromJsonFile($path);
    $responses = $spec->getResponseValidator();
    $validate = function (string $method, string $template, TestResponse $response) use ($responses): TestResponse {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Location');
        $responses->validate(new OperationAddress('/api/v1'.$template, strtolower($method)),
            new Response($response->getStatusCode(), $response->headers->all(), $response->getContent()));

        return $response;
    };

    $fixture = new SambaFixture;
    try {
        mkdir($fixture->root.'/EXPEDIENTES');
        config(['samba' => $fixture->settings()]);
        $this->app->instance(MountVerifier::class, $fixture);
        $headers = $this->authorizationBearer($this->authorizationAdmin('en'));
        $input = $this->caseInput(['expedient_type_id' => ExpedientType::factory()->create(['code' => '05'])->id]);
        $first = $validate('POST', '/expedients', $this->api('POST', '/api/v1/expedients', $input, $headers))
            ->assertCreated()->assertJsonPath('storage.status', 'failed');
        $case = Expedient::query()->findOrFail($first->json('data.id'));
        $base = "/api/v1/expedients/{$case->id}";
        $retry = '/expedients/{expedient}/storage/retry';
        $upload = '/expedients/{expedient}/uploads';

        $validate('POST', $retry, $this->api('POST', "$base/storage/retry", [], $headers))->assertConflict();
        mkdir($fixture->root.'/PLANTILLAS/05', 0700, true);
        $validate('POST', $retry, $this->api('POST', "$base/storage/retry", [], $headers))->assertOk();
        $validate('POST', '/expedients', $this->api('POST', '/api/v1/expedients', $input, $headers))
            ->assertCreated()->assertJsonPath('storage.status', 'ready');
        $fixture->id++;
        $validate('POST', $retry, $this->api('POST', "$base/storage/retry", [], $headers))->assertServiceUnavailable();
        $fixture->id--;

        $pdf = "%PDF-1.7\n% Contract fixture\n%%EOF\n";
        $multipart = new ServerRequest('POST', 'https://expedflow.example'.$base.'/uploads',
            $headers + ['Content-Type' => 'multipart/form-data; boundary=phase-i-contract']);
        $spec->getServerRequestValidator()->validate($multipart->withUploadedFiles([
            'file' => new PsrUploadedFile(Stream::create($pdf), strlen($pdf), UPLOAD_ERR_OK, 'Contract.pdf', 'application/pdf'),
        ]));
        $file = ['file' => UploadedFile::fake()->createWithContent('Contract.pdf', $pdf)];
        $validate('POST', $upload, $this->sambaMultipart($case->id, $file, $headers))->assertCreated();
        $validate('POST', $upload, $this->sambaMultipart($case->id, $file, $headers))->assertConflict();
        $validate('POST', $upload, $this->sambaMultipart($case->id, [], $headers))->assertUnprocessable();
        $validate('POST', $upload, $this->sambaMultipart($case->id, $file, []))->assertUnauthorized();
        $denied = $this->authorizationBearer(User::factory()->create());
        $validate('POST', $upload, $this->sambaMultipart($case->id, $file, $denied))->assertForbidden();
        $this->api('GET', "$base/uploads", [], $headers)->assertMethodNotAllowed();
        $this->api('DELETE', "$base/uploads", [], $headers)->assertMethodNotAllowed();
        $this->api('GET', "$base/files", [], $headers)->assertNotFound();

        $reference = $first->json('storage.incident_id');
        $incident = "/api/v1/incidents/$reference";
        $validate('GET', '/incidents', $this->api('GET', '/api/v1/incidents', [], $headers))->assertOk();
        $validate('GET', '/incidents', $this->api('GET', '/api/v1/incidents', [], $denied))->assertForbidden();
        foreach (['en', 'es', 'ca'] as $locale) {
            $reading = $this->authorizationBearer($this->authorizationAdmin($locale));
            $validate('GET', '/incidents/{incident}', $this->api('GET', $incident, [], $reading))->assertOk()
                ->assertJsonPath('data.message', __('samba.storage_template_missing', locale: $locale));
        }
        $this->get('http://expedflow.example/api/v1/incidents', $headers)->assertForbidden()->assertJsonPath('code', 'https_required');
    } finally {
        $fixture->clean();
    }
});
