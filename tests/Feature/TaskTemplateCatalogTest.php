<?php

use App\Models\ExpedientType;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\TaskTemplateChecklistItem;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Spatie\Permission\Models\Role;
use Tests\Support\AuthorizationHttp;
use Tests\Support\RefreshGuardedMySql;

uses(AuthorizationHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('template CRUD and full reorder are scoped to the type', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $type = ExpedientType::factory()->create();
    $external = TaskTemplate::factory()->create();
    $path = "/api/v1/expedient-types/{$type->id}/task-templates";
    $a = $this->api('POST', $path, ['name' => '  One  '], $headers)->assertCreated()
        ->assertJsonPath('data.name', 'One')->assertJsonPath('data.description', null)
        ->assertJsonPath('data.position', 1)->json('data.id');
    $b = $this->api('POST', $path, ['name' => 'Two', 'description' => "Text\n "], $headers)->assertCreated()
        ->assertJsonPath('data.description', "Text\n ")->assertJsonPath('data.position', 2)->json('data.id');
    $this->api('GET', "$path/{$external->id}", headers: $headers)->assertNotFound();
    $this->api('PATCH', "$path/{$external->id}", ['name' => 'Invalid target'], $headers)->assertNotFound();
    $this->api('PATCH', "$path/$b", ['name' => 'New', 'description' => null], $headers)
        ->assertOk()->assertJsonPath('data.description', null)->assertJsonPath('data.name', 'New');
    $this->api('PATCH', "$path/$b", ['expedient_type_id' => $external->expedient_type_id], $headers)->assertUnprocessable();
    $this->api('POST', $path, ['name' => 'Large', 'description' => str_repeat('é', 32768)], $headers)
        ->assertUnprocessable()->assertJsonValidationErrors('description');
    $this->api('PUT', "$path/order", ['template_ids' => [$b, $a]], $headers)->assertOk()->assertJsonPath('data.0.id', $b);
    $this->api('PUT', "$path/order", ['template_ids' => [$b, $external->id]], $headers)->assertConflict();
    $this->api('GET', $path, headers: $headers)->assertOk()->assertJsonPath('data.0.id', $b);
    $this->api('GET', "$path/$a", headers: $headers)->assertOk()->assertJsonPath('data.id', $a);
});

test('template checklists use exact scope and cascade only with their blueprint', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $template = TaskTemplate::factory()->create();
    $root = "/api/v1/expedient-types/{$template->expedient_type_id}/task-templates/{$template->id}";
    $path = "$root/checklist";
    $foreign = TaskTemplateChecklistItem::factory()->create();
    $live = Task::factory()->create();
    $first = $this->api('POST', $path, ['name' => '  First  '], $headers)->assertCreated()
        ->assertJsonPath('data.name', 'First')->assertJsonMissingPath('data.completed')->json('data.id');
    $second = $this->api('POST', $path, ['name' => 'Second'], $headers)->assertCreated()
        ->assertJsonPath('data.position', 2)->json('data.id');
    $this->api('POST', $path, ['name' => 'Extra', 'completed' => true], $headers)->assertUnprocessable();
    $this->api('GET', "$path/{$foreign->id}", headers: $headers)->assertNotFound();
    $this->api('DELETE', "$path/{$foreign->id}", headers: $headers)->assertNotFound();
    $this->api('PATCH', "$path/$first", ['name' => 'Updated'], $headers)->assertOk();
    $this->api('GET', "$path/$first", headers: $headers)->assertJsonPath('data.name', 'Updated');
    $this->api('PUT', "$path/order", ['item_ids' => [$second, $first]], $headers)->assertOk()->assertJsonPath('data.0.id', $second);
    $this->api('PUT', "$path/order", ['item_ids' => [$second]], $headers)->assertConflict();
    $this->api('GET', $path, headers: $headers)->assertOk()->assertJsonPath('data.0.id', $second);
    $this->api('DELETE', "$path/$first", headers: $headers)->assertOk()->assertJsonPath('code', 'template_item_deleted');
    $this->api('DELETE', $root, headers: $headers)->assertOk()->assertJsonPath('code', 'template_deleted');
    $this->assertDatabaseMissing('task_template_checklist_items', ['id' => $second]);
    $this->assertModelExists($foreign);
    $this->assertModelExists($live);
});

test('a custom template writer also needs type view and has no implicit template read grant', function (): void {
    $role = Role::create(['name' => 'Template editor', 'guard_name' => 'web']);
    $role->givePermissionTo('task-templates.manage');
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole($role));
    $type = ExpedientType::factory()->create();
    $path = "/api/v1/expedient-types/{$type->id}/task-templates";
    $this->api('POST', $path, ['name' => 'Blueprint'], $headers)->assertForbidden();
    $role->givePermissionTo('expedient-types.view');
    $this->api('POST', $path, ['name' => 'Blueprint'], $headers)->assertCreated();
    $this->api('GET', $path, headers: $headers)->assertForbidden();
});
