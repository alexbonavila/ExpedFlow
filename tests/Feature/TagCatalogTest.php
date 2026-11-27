<?php

use App\Models\Expedient;
use App\Models\Tag;
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

test('tags preserve normalized labels and strict RGB and reject duplicate collation keys', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $list = '/api/v1/tags';
    $first = $this->api('POST', $list, ['name' => "\u{00a0}Café  Red\u{2003}", 'color' => '#aDe012'], $headers)
        ->assertCreated()->assertJsonPath('data.name', 'Café  Red')->assertJsonPath('data.color', '#aDe012');
    $path = $list.'/'.$first->json('data.id');
    $this->api('GET', $path, headers: $headers)->assertOk()->assertExactJson($first->json());
    $this->api('GET', $list, headers: $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('links');
    $this->api('POST', $list, ['name' => 'CAFE  RED', 'color' => '#000000'], $headers)->assertConflict()
        ->assertJsonPath('code', 'tag_name_conflict');
    foreach (['#fff', ' #aDe012', '#aDe012ff', '#aDe01x', 123456] as $color) {
        $this->api('PATCH', $path, ['color' => $color], $headers)->assertUnprocessable()->assertJsonValidationErrors('color');
    }
    $second = Tag::factory()->create(['name' => 'Distinct']);
    $this->api('PATCH', $path, ['name' => $second->name], $headers)->assertConflict();
    $this->api('PATCH', $path, ['color' => '#123ABC'], $headers)->assertOk()->assertJsonPath('data.color', '#123ABC');
});

test('case tags replace atomically and retain links through trash while deletion cascades only links', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $admin = $this->authorizationBearer($this->authorizationAdmin());
    $case = Expedient::factory()->create()->refresh();
    $other = Expedient::factory()->create();
    $first = Tag::factory()->create(['name' => 'A']);
    $second = Tag::factory()->create(['name' => 'B']);
    $casePath = "/api/v1/expedients/{$case->id}";
    $path = "$casePath/tags";
    $this->api('PUT', $path, ['tag_ids' => [$second->id, $first->id]], $headers)
        ->assertOk()->assertExactJson(['data' => ['tag_ids' => [$first->id, $second->id]]]);
    $this->api('PUT', $path, ['tag_ids' => [$second->id, 999999]], $headers)->assertUnprocessable()->assertJsonValidationErrors('tag_ids');
    $this->api('PUT', $path, ['tag_ids' => [$second->id, $second->id]], $headers)->assertUnprocessable();
    $this->api('PUT', $path, ['tag_ids' => [], 'pivot' => true], $headers)->assertUnprocessable();
    $this->api('GET', $path, headers: $headers)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $first->id)->assertJsonMissingPath('data.0.pivot');
    expect($case->fresh()->getAttributes())->toBe($case->getAttributes());
    $this->api('PUT', $path, ['tag_ids' => []], $headers)->assertOk()->assertJsonPath('data.tag_ids', []);
    $this->api('PUT', $path, ['tag_ids' => [$first->id, $second->id]], $headers)->assertOk();
    $other->tags()->attach($first);
    $this->api('DELETE', $casePath, headers: $headers)->assertOk();
    $this->api('PUT', $path, ['tag_ids' => []], $headers)->assertNotFound();
    $this->api('GET', $path, headers: $headers)->assertNotFound();
    $this->assertDatabaseCount('expedient_tag', 3);
    $this->api('DELETE', "/api/v1/tags/{$first->id}", headers: $headers)->assertOk()->assertJsonPath('code', 'tag_deleted');
    $this->assertDatabaseCount('expedient_tag', 1);
    $this->api('POST', "$casePath/restore", headers: $headers)->assertOk();
    $this->api('GET', $path, headers: $headers)->assertOk()->assertJsonPath('data.0.id', $second->id);
    $this->api('DELETE', $casePath, headers: $headers)->assertOk();
    $this->api('POST', "$casePath/force-delete", headers: $admin)->assertOk();
    $this->assertDatabaseEmpty('expedient_tag');
    $this->assertModelExists($second);
    $this->assertModelExists($other);
});

test('association permissions never disclose tag details and case view is required', function (): void {
    $role = Role::create(['name' => 'Tag associator', 'guard_name' => 'web']);
    $role->givePermissionTo('tags.associate');
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole($role));
    $tag = Tag::factory()->create();
    $case = Expedient::factory()->create()->refresh();
    $path = "/api/v1/expedients/{$case->id}/tags";
    $this->api('PUT', $path, ['tag_ids' => [$tag->id]], $headers)->assertForbidden();
    $role->givePermissionTo('expedients.view');
    $this->api('PUT', $path, ['tag_ids' => [$tag->id]], $headers)->assertOk()
        ->assertExactJson(['data' => ['tag_ids' => [$tag->id]]]);
    $this->api('GET', $path, headers: $headers)->assertForbidden();
    $this->api('GET', "/api/v1/tags/{$tag->id}", headers: $headers)->assertForbidden();
});
