<?php

use App\Models\Expedient;
use App\Models\Status;
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

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

function phaseGContractResponse(TestResponse $res, string $method, string $path): TestResponse
{
    $schema = (new ValidatorBuilder)->fromJsonFile(base_path('tests/Fixtures/phase-g-openapi.json'));
    $res->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Location');
    $schema->getResponseValidator()->validate(new OperationAddress('/api/v1'.$path, strtolower($method)),
        new Response($res->getStatusCode(), $res->headers->all(), $res->getContent()));

    return $res;
}

test('the Phase G OpenAPI fixture matches the live bearer API across every operation', function (): void {
    $file = base_path('tests/Fixtures/phase-g-openapi.json');
    $document = Reader::readFromJsonFile($file);
    $this->assertTrue($document->validate(), implode(PHP_EOL, $document->getErrors()));
    $schema = (new ValidatorBuilder)->fromJsonFile($file);
    $requests = $schema->getServerRequestValidator();
    $responses = $schema->getResponseValidator();
    $actor = $this->authorizationAdmin();
    $headers = $this->authorizationBearer($actor);
    $case = Expedient::factory()->create();
    $status = Status::factory()->create();
    $taskList = '/expedients/{expedient}/tasks';
    $root = "/expedients/{$case->id}/tasks";
    $taskPath = '/tasks/{task}';

    $run = function (string $method, string $path, string $template, int $status = 200, array $fields = []) use ($headers, $requests, $responses): TestResponse {
        if ($fields !== [] && $status < 400) {
            $requests->validate(new ServerRequest($method, 'https://expedflow.example/api/v1'.$path,
                $headers + ['Content-Type' => 'application/json'], json_encode($fields, JSON_THROW_ON_ERROR)));
        }
        $result = $this->api($method, '/api/v1'.$path, $fields, $headers)->assertStatus($status)
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeaderMissing('Location');
        $responses->validate(new OperationAddress('/api/v1'.$template, strtolower($method)),
            new Response($result->getStatusCode(), $result->headers->all(), $result->getContent()));

        return $result;
    };

    $run('GET', $root, $taskList)->assertExactJson(['data' => []]);
    $task = $run('POST', $root, $taskList, 201, ['name' => 'Contract task', 'status_id' => $case->status_id])->json('data.id');
    $path = "/tasks/$task";
    $run('GET', $path, $taskPath)->assertJsonMissingPath('data.active_position');
    $run('PATCH', $path, $taskPath, fields: ['scheduled_date' => '2028-02-29', 'scheduled_time' => null]);
    $run('PATCH', $path, $taskPath, 422)->assertJsonValidationErrors('body');
    $run('POST', "$path/move", "$taskPath/move", fields: ['status_id' => $status->id, 'position' => 1]);
    $run('GET', "$path/assignee-candidates", "$taskPath/assignee-candidates");
    $run('GET', "$path/assignees", "$taskPath/assignees")->assertExactJson(['data' => []]);
    $run('PUT', "$path/assignees", "$taskPath/assignees", fields: ['user_ids' => [$actor->id]]);
    $run('GET', '/calendar/tasks?from=2028-02-29', '/calendar/tasks')->assertJsonPath('data.0.all_day', true);

    $comments = "$path/comments";
    $commentList = "$taskPath/comments";
    $commentPath = "$commentList/{comment}";
    $comment = $run('POST', $comments, $commentList, 201, ['body' => 'Contract comment'])->json('data.id');
    $run('GET', $comments, $commentList);
    $run('GET', "$comments/$comment", $commentPath);
    $run('PATCH', "$comments/$comment", $commentPath, fields: ['body' => 'Edited comment']);
    $run('DELETE', "$comments/$comment", $commentPath);
    $run('GET', "$comments/trash", "$commentList/trash");
    $run('GET', "$comments/trash/$comment", "$commentList/trash/{comment}");
    $run('POST', "$comments/$comment/restore", "$commentPath/restore");
    $run('DELETE', "$comments/$comment", $commentPath);
    $run('POST', "$comments/$comment/force-delete", "$commentPath/force-delete");

    $checklist = "$path/checklist";
    $checklistPath = "$taskPath/checklist";
    $itemPath = "$checklistPath/{item}";
    $item = $run('POST', $checklist, $checklistPath, 201, ['name' => 'Contract item'])->json('data.id');
    $run('GET', $checklist, $checklistPath);
    $run('GET', "$checklist/$item", $itemPath);
    $run('GET', "$checklist/not-a-number", $itemPath, 404);
    $run('PATCH', "$checklist/$item", $itemPath, fields: ['completed' => true]);
    $run('PUT', "$checklist/order", "$checklistPath/order", fields: ['item_ids' => [$item]]);
    $run('PUT', "$checklist/order", "$checklistPath/order", 409, ['item_ids' => []]);
    $run('DELETE', "$checklist/$item", $itemPath);

    $run('DELETE', $path, $taskPath);
    $run('GET', "$root/trash", "$taskList/trash");
    $run('GET', "$root/trash/$task", "$taskList/trash/{task}");
    $foreign = Expedient::factory()->create();
    $run('GET', "/expedients/{$foreign->id}/tasks/trash/$task", "$taskList/trash/{task}", 404);
    $run('POST', "$path/restore", "$taskPath/restore");
    $run('DELETE', $path, $taskPath);
    $run('POST', "$path/force-delete", "$taskPath/force-delete");
    $run('GET', $path, $taskPath, 404);
});

test('the Phase G contract preserves real session CSRF and fresh custom grants, with localized errors in both transports', function (): void {
    $role = Role::create(['name' => 'Scoped task writer', 'guard_name' => 'web'])
        ->givePermissionTo(['expedients.view', 'tasks.create']);
    $actor = User::factory()->create(['locale' => 'ca'])->assignRole($role);
    $case = Expedient::factory()->create();
    $root = "/expedients/{$case->id}/tasks";
    $list = '/expedients/{expedient}/tasks';
    $detail = '/tasks/{task}';
    $fields = ['name' => 'Session task', 'status_id' => $case->status_id];
    phaseGContractResponse($this->api('GET', '/api/v1'.$root, headers: ['Accept-Language' => 'en']), 'GET', $list)
        ->assertUnauthorized()->assertJsonPath('message', 'Sign in to continue.');

    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $actor->email, 'password' => 'password'])->assertOk();
    phaseGContractResponse($this->api('POST', '/api/v1'.$root, $fields, browser: true), 'POST', $list)
        ->assertStatus(419)->assertJsonPath('code', 'csrf_mismatch');
    phaseGContractResponse($this->spa('POST', $root, $fields + ['scheduled_date' => '2028-02-30']), 'POST', $list)
        ->assertUnprocessable()->assertJsonPath('errors.scheduled_date.0', 'Introdueix una data vàlida en format AAAA-MM-DD.');
    $id = phaseGContractResponse($this->spa('POST', $root, $fields), 'POST', $list)
        ->assertCreated()->json('data.id');
    $path = "/tasks/$id";
    phaseGContractResponse($this->spa('GET', $path), 'GET', $detail)
        ->assertForbidden()->assertJsonPath('message', 'No tens permís per fer aquesta acció.');
    $role->givePermissionTo(['tasks.view', 'tasks.update']);
    phaseGContractResponse($this->spa('PATCH', $path, ['name' => 'Session edit']), 'PATCH', $detail)
        ->assertOk()->assertJsonPath('data.name', 'Session edit');
    $role->revokePermissionTo('tasks.update');
    phaseGContractResponse($this->spa('PATCH', $path, ['name' => 'Revoked']), 'PATCH', $detail)->assertForbidden();

    $roleless = $this->authorizationBearer(User::factory()->create(['locale' => 'es']));
    phaseGContractResponse($this->api('GET', '/api/v1/tasks/999999', headers: $roleless), 'GET', $detail)
        ->assertForbidden()->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    $disabled = $this->authorizationBearer(User::factory()->create(['disabled_at' => now()])->assignRole('user'));
    phaseGContractResponse($this->api('GET', '/api/v1'.$path, headers: $disabled), 'GET', $detail)->assertUnauthorized();
    $spanish = $this->authorizationBearer(User::factory()->create(['locale' => 'es'])->assignRole('user'));
    phaseGContractResponse($this->api('POST', '/api/v1'.$root, $fields + ['scheduled_time' => '00:00'], $spanish), 'POST', $list)
        ->assertUnprocessable()->assertJsonPath('errors.scheduled_time.0', 'Indica una fecha antes de proporcionar una hora.');
});
