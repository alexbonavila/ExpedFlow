<?php

use App\Models\User;
use cebe\openapi\Reader;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Tests\Support\CaseHttp;
use Tests\Support\RefreshGuardedMySql;

uses(CaseHttp::class, RefreshGuardedMySql::class);

test('Phase F OpenAPI contracts validate actual client, lookup, case and lifecycle HTTP responses', function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
    $specification = base_path('tests/Fixtures/phase-f-openapi.json');
    $document = Reader::readFromJsonFile($specification);
    $this->assertTrue($document->validate(), implode(PHP_EOL, $document->getErrors()));
    $spec = (new ValidatorBuilder)->fromJsonFile($specification);
    $requests = $spec->getServerRequestValidator();
    $responses = $spec->getResponseValidator();
    $headers = $this->authorizationBearer($this->authorizationAdmin());

    $validate = function (string $method, string $template, TestResponse $response) use ($responses): TestResponse {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Location');
        $responses->validate(new OperationAddress('/api/v1'.$template, strtolower($method)),
            new Response($response->getStatusCode(), $response->headers->all(), $response->getContent()));

        return $response;
    };
    $run = function (string $method, string $path, string $template, array $payload = []) use ($headers, $validate, $requests): TestResponse {
        if ($payload !== []) {
            $requests->validate(new ServerRequest($method, 'https://expedflow.example/api/v1'.$path,
                $headers + ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)));
        }

        return $validate($method, $template, $this->api($method, '/api/v1'.$path, $payload, $headers));
    };
    $clientResult = $run('POST', '/clients', '/clients', ['name' => 'Contract client'])->assertCreated();
    $client = $clientResult->json('data.id');
    $run('GET', '/clients', '/clients')->assertOk();
    $run('GET', "/clients/$client", '/clients/{client}')->assertOk();
    $run('PATCH', "/clients/$client", '/clients/{client}', ['name' => 'Renamed contract client'])->assertOk();
    $run('POST', '/clients', '/clients', ['name' => 'RENAMED CONTRACT CLIENT'])->assertConflict();
    $run('GET', '/expedient-types', '/expedient-types')->assertOk();
    $run('GET', '/statuses', '/statuses')->assertOk();
    $created = $run('POST', '/expedients', '/expedients', $this->caseInput(['requester_client_id' => $client]))->assertCreated();
    $id = $created->json('data.id');
    $path = "/expedients/$id";
    $run('GET', '/expedients', '/expedients')->assertOk();
    $run('GET', $path, '/expedients/{expedient}')->assertOk();
    $run('PATCH', $path, '/expedients/{expedient}', ['description' => null])->assertOk();
    $run('DELETE', $path, '/expedients/{expedient}')->assertOk();
    $run('GET', '/expedients/trash', '/expedients/trash')->assertOk();
    $run('GET', "/expedients/trash/$id", '/expedients/trash/{expedient}')->assertOk();
    $run('POST', "$path/restore", '/expedients/{expedient}/restore')->assertOk();
    $run('POST', "$path/force-delete", '/expedients/{expedient}/force-delete')->assertNotFound();
    $run('DELETE', $path, '/expedients/{expedient}')->assertOk();
    $run('POST', "$path/force-delete", '/expedients/{expedient}/force-delete')->assertOk();
    $run('DELETE', "/clients/$client", '/clients/{client}')->assertOk();

    $validate('POST', '/expedients', $this->api('POST', '/api/v1/expedients', [], $headers)->assertUnprocessable());
    $validate('POST', '/expedients', $this->api('POST', '/api/v1/expedients', [], browser: true)->assertStatus(419));
    $validate('GET', '/expedients', $this->api('GET', '/api/v1/expedients')->assertUnauthorized());
    $roleless = User::factory()->create();
    $validate('GET', '/expedients', $this->api('GET', '/api/v1/expedients',
        headers: $this->authorizationBearer($roleless))->assertForbidden());
});
