<?php

use App\Models\User;
use cebe\openapi\Reader;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Spatie\Permission\Models\Role;
use Tests\Support\AuthorizationHttp;
use Tests\Support\RefreshGuardedMySql;

uses(AuthorizationHttp::class, RefreshGuardedMySql::class);

test('Phase E OpenAPI validates real authorized requests and success and error responses', function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
    $specification = base_path('tests/Fixtures/phase-e-openapi.json');
    $document = Reader::readFromJsonFile($specification);
    $this->assertTrue($document->validate(), implode(PHP_EOL, $document->getErrors()));
    $spec = (new ValidatorBuilder)->fromJsonFile($specification);
    $requestValidator = $spec->getServerRequestValidator();
    $responseValidator = $spec->getResponseValidator();
    $admin = $this->authorizationAdmin();
    $headers = $this->authorizationBearer($admin);
    $user = User::factory()->create()->assignRole('user');

    $validate = function (string $method, string $template, TestResponse $response) use ($responseValidator): TestResponse {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Location');
        $responseValidator->validate(new OperationAddress($template, strtolower($method)),
            new Response($response->getStatusCode(), $response->headers->all(), $response->getContent()));

        return $response;
    };
    $call = function (string $method, string $path, string $template, array $payload = []) use ($requestValidator, $validate, $headers): TestResponse {
        if ($payload !== []) {
            $requestValidator->validate(new ServerRequest($method, 'https://expedflow.example'.$path,
                $headers + ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR)));
        }

        return $validate($method, $template, $this->api($method, $path, $payload, $headers));
    };

    $catalog = $call('GET', '/api/v1/permissions', '/api/v1/permissions')->assertOk();
    $schema = json_decode(file_get_contents($specification), true, flags: JSON_THROW_ON_ERROR);
    expect(array_column($catalog->json('data'), 'key'))->toBe($schema['components']['schemas']['PermissionKey']['enum']);
    $call('GET', '/api/v1/roles', '/api/v1/roles')->assertOk();
    $create = $call('POST', '/api/v1/roles', '/api/v1/roles', ['name' => 'Contract reviewer', 'permissions' => ['tasks.view']])
        ->assertCreated();
    $role = $create->json('data.id');
    $path = "/api/v1/roles/$role";
    $call('GET', $path, '/api/v1/roles/{role}')->assertOk();
    $call('PATCH', $path, '/api/v1/roles/{role}', ['name' => 'Contract operator'])->assertOk();
    $call('PUT', "$path/permissions", '/api/v1/roles/{role}/permissions', ['permissions' => ['tasks.update']])->assertOk();
    $authorization = "/api/v1/users/{$user->id}/authorization";
    $assignment = "/api/v1/users/{$user->id}/roles";
    $call('GET', $authorization, '/api/v1/users/{user}/authorization')->assertOk();
    $call('PUT', $assignment, '/api/v1/users/{user}/roles', ['role_ids' => [$role]])->assertOk()
        ->assertExactJson(['data' => ['roles' => [['id' => $role, 'name' => 'Contract operator']], 'permissions' => ['tasks.update']]]);
    $call('DELETE', $path, '/api/v1/roles/{role}')->assertConflict();
    $call('PUT', $assignment, '/api/v1/users/{user}/roles', ['role_ids' => []])->assertOk();
    $call('DELETE', $path, '/api/v1/roles/{role}')->assertOk()->assertJsonPath('code', 'role_deleted');
    $call('GET', $path, '/api/v1/roles/{role}')->assertNotFound();
    $validate('GET', '/api/v1/roles', $this->api('GET', '/api/v1/roles')->assertUnauthorized());
    $validate('GET', '/api/v1/roles', $this->api('GET', '/api/v1/roles', headers: $this->authorizationBearer($user))->assertForbidden());
    $validate('POST', '/api/v1/roles', $this->api('POST', '/api/v1/roles', ['name' => 'Invalid', 'permissions' => ['*']], $headers)->assertUnprocessable());
    $validate('PUT', '/api/v1/users/{user}/roles', $this->api('PUT', $assignment, ['role_ids' => []], browser: true)->assertStatus(419));
});

test('admin-defined name and authorization conflicts retain safe localized JSON', function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
    $admin = $this->authorizationAdmin('ca');
    $headers = $this->authorizationBearer($admin);
    $role = Role::findByName('admin', 'web');
    $this->api('PATCH', "/api/v1/roles/{$role->id}", ['name' => 'Owner'], $headers)->assertConflict()->assertExactJson([
        'code' => 'system_role', 'message' => 'Els rols del sistema no es poden reanomenar ni suprimir.',
    ]);
    $this->api('PUT', "/api/v1/users/{$admin->id}/roles", ['role_ids' => []], $headers)->assertConflict()->assertExactJson([
        'code' => 'self_demotion', 'message' => 'No pots retirar la teva pertinença al rol d’administrador.',
    ]);
});
