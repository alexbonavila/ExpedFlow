<?php

use App\Models\Expedient;
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

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('Phase H authenticated catalog, template, case and board payloads satisfy the OpenAPI contract', function (): void {
    $specification = base_path('tests/Fixtures/phase-h-openapi.json');
    $document = Reader::readFromJsonFile($specification);
    $this->assertTrue($document->validate(), implode(PHP_EOL, $document->getErrors()));
    $spec = (new ValidatorBuilder)->fromJsonFile($specification);
    $requests = $spec->getServerRequestValidator();
    $responses = $spec->getResponseValidator();
    $headers = $this->authorizationBearer($this->authorizationAdmin());
    $validate = function (string $method, string $route, TestResponse $response) use ($responses): TestResponse {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Location');
        $responses->validate(new OperationAddress('/api/v1'.$route, strtolower($method)),
            new Response($response->getStatusCode(), $response->headers->all(), $response->getContent()));

        return $response;
    };
    $run = function (string $method, string $path, string $route, array $data = []) use ($headers, $requests, $validate): TestResponse {
        if ($data !== []) {
            $requests->validate(new ServerRequest($method, 'https://expedflow.example/api/v1'.$path,
                $headers + ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR)));
        }

        return $validate($method, $route, $this->api($method, '/api/v1'.$path, $data, $headers));
    };

    $type = $run('POST', '/expedient-types', '/expedient-types', ['name' => 'Contract type', 'code' => '01'])
        ->assertCreated()->json('data.id');
    $run('GET', "/expedient-types/$type", '/expedient-types/{type}')->assertOk();
    $run('GET', '/expedient-types', '/expedient-types')->assertOk();
    $status = $run('POST', '/statuses', '/statuses', ['name' => 'Contract status'])->assertCreated()->json('data.id');
    $run('PUT', '/statuses/order', '/statuses/order', ['status_ids' => [$status]])->assertOk();
    $run('PATCH', "/statuses/$status", '/statuses/{status}', ['name' => 'Renamed'])->assertOk();
    $templates = "/expedient-types/$type/task-templates";
    $templateRoute = '/expedient-types/{type}/task-templates';
    $template = $run('POST', $templates, $templateRoute, ['name' => 'Contract blueprint'])->assertCreated()->json('data.id');
    $run('PUT', "$templates/order", "$templateRoute/order", ['template_ids' => [$template]])->assertOk();
    $items = "$templates/$template/checklist";
    $itemRoute = $templateRoute.'/{template}/checklist';
    $item = $run('POST', $items, $itemRoute, ['name' => 'Contract item'])->assertCreated()->json('data.id');
    $run('GET', "$items/$item", "$itemRoute/{item}")->assertOk();
    $run('PUT', "$items/order", "$itemRoute/order", ['item_ids' => [$item]])->assertOk();
    $tag = $run('POST', '/tags', '/tags', ['name' => 'Contract tag', 'color' => '#DE0123'])->assertCreated()->json('data.id');
    $run('GET', '/tags', '/tags')->assertOk();
    $id = $run('POST', '/expedients', '/expedients', $this->caseInput([
        'status_id' => $status, 'expedient_type_id' => $type, 'copy_template' => true, 'template_status_id' => $status,
    ]))->assertCreated()->json('data.id');
    $case = "/expedients/$id";
    $caseRoute = '/expedients/{expedient}';
    $run('PUT', "$case/tags", "$caseRoute/tags", ['tag_ids' => [$tag]])->assertOk();
    $run('GET', "$case/tags", "$caseRoute/tags")->assertOk();
    $run('GET', "$case/preferences", "$caseRoute/preferences")->assertOk()->assertJsonPath('data.position', null);
    $run('PATCH', "$case/preferences", "$caseRoute/preferences", ['hidden' => true])->assertOk();
    $run('POST', "$case/move", "$caseRoute/move", ['status_id' => $status, 'position' => 1])->assertOk();
    $run('GET', '/expedients/board', '/expedients/board')->assertOk()->assertJsonPath('data.0.expedients.0.hidden', true);
    $run('DELETE', "$items/$item", "$itemRoute/{item}")->assertOk();
    $run('DELETE', "$templates/$template", "$templateRoute/{template}")->assertOk();
    $run('DELETE', "/tags/$tag", '/tags/{tag}')->assertOk();
    $run('DELETE', "/expedient-types/$type", '/expedient-types/{type}')->assertConflict();

    $validate('PATCH', "$caseRoute/preferences",
        $this->api('PATCH', "/api/v1$case/preferences", ['hidden' => 1], $headers)->assertUnprocessable());
    $validate('GET', "$itemRoute/{item}",
        $this->api('GET', "/api/v1$items/999999", headers: $headers)->assertNotFound());
    $validate('GET', '/tags', $this->api('GET', '/api/v1/tags')->assertUnauthorized());
});

test('Phase H session writes require CSRF and share localized contracts with bearer access', function (): void {
    $case = Expedient::factory()->create();
    $actor = User::factory()->create(['locale' => 'es'])->assignRole('user');
    $path = "/expedients/{$case->id}/preferences";
    $board = '/api/v1/expedients/board';
    $responses = (new ValidatorBuilder)->fromJsonFile(base_path('tests/Fixtures/phase-h-openapi.json'))->getResponseValidator();
    $validate = function (string $method, string $route, TestResponse $response) use ($responses): void {
        $responses->validate(new OperationAddress('/api/v1'.$route, strtolower($method)),
            new Response($response->getStatusCode(), $response->headers->all(), $response->getContent()));
    };

    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $actor->email, 'password' => 'password'])->assertOk();
    $route = '/expedients/{expedient}/preferences';
    $validate('PATCH', $route, $this->api('PATCH', '/api/v1'.$path, ['hidden' => true], browser: true)->assertStatus(419));
    $validate('PATCH', $route, $this->spa('PATCH', $path, ['hidden' => true])->assertOk());
    $bearer = $this->authorizationBearer($actor);
    $this->api('GET', '/api/v1'.$path, headers: $bearer)->assertOk()
        ->assertExactJson(['data' => ['expedient_id' => $case->id, 'position' => null, 'hidden' => true]]);
    $validate('PATCH', $route, $this->spa('PATCH', $path, ['hidden' => true, 'owner' => $actor->id])
        ->assertUnprocessable()->assertJsonPath('errors.owner.0', 'Este campo no es compatible.'));
    $validate('PATCH', $route, $this->api('PATCH', '/api/v1'.$path, headers: $bearer)
        ->assertUnprocessable()->assertJsonPath('errors.body.0', 'El cuerpo de la solicitud debe ser un objeto JSON.'));
    $roleless = User::factory()->create()->givePermissionTo('kanban.manage', 'expedients.view');
    $disabled = User::factory()->create(['disabled_at' => now()])->assignRole('user');
    $validate('GET', '/expedients/board', $this->api('GET', $board, headers: $this->authorizationBearer($roleless))->assertForbidden());
    $validate('GET', '/expedients/board', $this->api('GET', $board, headers: $this->authorizationBearer($disabled))->assertUnauthorized());
});
