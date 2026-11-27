<?php

use App\Models\User;
use cebe\openapi\Reader;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Response;
use Spatie\Permission\Models\Role;
use Tests\Support\IdentityHttp;
use Tests\Support\RefreshGuardedMySql;

uses(IdentityHttp::class, RefreshGuardedMySql::class);

test('Phase D identity contract validates real Laravel success and error responses', function () {
    $this->identityHttp();
    $specification = base_path('tests/Fixtures/phase-d-openapi.json');
    $document = Reader::readFromJsonFile($specification);
    $this->assertTrue($document->validate(), implode(PHP_EOL, $document->getErrors()));
    $validator = (new ValidatorBuilder)->fromJsonFile($specification)->getResponseValidator();
    $user = User::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole(Role::create(['name' => User::ADMIN_ROLE, 'guard_name' => 'web']));
    $adminHeaders = ['Authorization' => 'Bearer '.$admin->createToken('Contract fixture')->plainTextToken];
    $this->freshCsrf();
    Queue::fake();

    foreach ([
        ['/api/v1/me', 'get', $this->api('GET', '/api/v1/me')->assertUnauthorized()],
        ['/api/v1/auth/login', 'post', $this->api('POST', '/api/v1/auth/login', [], browser: true)->assertStatus(419)],
        ['/api/v1/auth/login', 'post', $this->spa('POST', '/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk()],
        ['/api/v1/me', 'get', $this->spa('GET', '/me')->assertOk()],
        ['/api/v1/me/locale', 'patch', $this->spa('PATCH', '/me/locale', ['locale' => 'ru'])->assertUnprocessable()],
        ['/api/v1/me/locale', 'patch', $this->spa('PATCH', '/me/locale', ['locale' => 'ca'])->assertOk()],
        ['/api/v1/auth/logout', 'post', $this->spa('POST', '/auth/logout')->assertOk()],
        ['/api/v1/auth/token', 'post', $this->api('POST', '/api/v1/auth/token', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Contract fixture'])->assertCreated()],
        ['/api/v1/auth/forgot-password', 'post', $this->api('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()],
        ['/api/v1/users', 'get', $this->api('GET', '/api/v1/users', headers: $adminHeaders)->assertOk()],
        ['/api/v1/users/{user}', 'get', $this->api('GET', "/api/v1/users/{$user->id}", headers: $adminHeaders)->assertOk()],
        ['/api/v1/users/{user}/disable', 'post', $this->api('POST', "/api/v1/users/{$admin->id}/disable", headers: $adminHeaders)->assertConflict()],
    ] as [$path, $method, $result]) {
        $validator->validate(new OperationAddress($path, $method),
            new Response($result->getStatusCode(), $result->headers->all(), $result->getContent()));
    }
});

test('unexpected v1 errors hide all internal details even with debug configured', function () {
    config(['app.debug' => true]);
    Route::get('/api/v1/identity-test-fault', fn () => throw new RuntimeException('Synthetic diagnostic marker'));
    $this->get('/api/v1/identity-test-fault')->assertInternalServerError()->assertExactJson([
        'message' => 'An unexpected error occurred.',
        'code' => 'server_error',
    ])->assertHeaderMissing('Location');
    $this->get('/api/v1')->assertNotFound()->assertJsonMissingPath('trace');
    $this->get('/identity-test-missing')->assertNotFound()->assertHeader('Content-Type', 'text/html; charset=utf-8');
});

test('v1 preserves the status and cache policy for generic HTTP errors', function (int $status, string $code) {
    Route::get('/api/v1/identity-test-http-error', fn () => abort($status, 'Synthetic private detail'));
    $this->get('/api/v1/identity-test-http-error')->assertStatus($status)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('code', $code)->assertJsonMissingPath('trace');
})->with([[400, 'bad_request'], [503, 'unavailable']]);
