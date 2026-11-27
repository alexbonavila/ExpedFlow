<?php

use App\Models\Expedient;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\StatusSeeder;
use Tests\Support\AuthorizationHttp;
use Tests\Support\RefreshGuardedMySql;

uses(AuthorizationHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('reserved administrators rearrange and rename statuses while seeding preserves explicit changes', function (): void {
    app(StatusSeeder::class)->run();
    $headers = $this->authorizationBearer($this->authorizationAdmin());
    $path = '/api/v1/statuses';
    $initial = Status::query()->orderBy('position')->get();
    $first = $initial->first();
    $last = $initial->last();
    foreach (['es' => 'En espera', 'ca' => 'En espera', 'en' => 'Waiting'] as $locale => $label) {
        $this->api('GET', $path.'/'.$first->id, headers: $this->authorizationBearer($this->authorizationAdmin($locale)))
            ->assertOk()->assertJsonPath('data.name', $label);
    }

    $new = $this->api('POST', $path, ['name' => '  Shared  '], $headers)
        ->assertCreated()->assertJsonPath('data.position', $last->position + 1)->json('data.id');
    $this->api('PATCH', $path.'/'.$first->id, ['name' => 'Renamed'], $headers)
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonMissingPath('data.name_translations');
    $this->api('GET', $path.'/'.$first->id, headers: $this->authorizationBearer($this->authorizationAdmin('ca')))->assertJsonPath('data.name', 'Renamed');
    $this->api('DELETE', $path.'/'.$last->id, headers: $headers)->assertOk()->assertJsonPath('code', 'status_deleted');
    app(StatusSeeder::class)->run();

    $ids = Status::query()->orderByDesc('position')->pluck('id')->all();
    expect($ids)->toHaveCount(6)->and($ids[0])->toBe($new);
    $this->api('PUT', $path.'/order', ['status_ids' => $ids], $headers)->assertOk()
        ->assertJsonPath('data.0.id', $new)->assertJsonPath('data.0.position', 1);
    $this->api('PUT', $path.'/order', ['status_ids' => [$new]], $headers)->assertConflict()
        ->assertJsonPath('code', 'order_membership_conflict');
    foreach ([[$new, $new], [(string) $new], (object) ['0' => $new]] as $invalid) {
        $this->api('PUT', $path.'/order', ['status_ids' => $invalid], $headers)->assertUnprocessable();
    }
    expect(Status::query()->orderBy('position')->pluck('id')->all())->toBe($ids);
    expect($first->refresh()->name_translations)->toBeNull();
});

test('ordinary direct grants cannot administer statuses and trash still holds references', function (): void {
    $case = Expedient::factory()->create();
    $task = Task::factory()->create(['expedient_id' => $case->id]);
    $user = User::factory()->create()->assignRole('user')->givePermissionTo('statuses.manage');
    $path = '/api/v1/statuses';
    $headers = $this->authorizationBearer($this->authorizationAdmin());

    $this->api('DELETE', $path.'/999999', headers: $this->authorizationBearer($user))->assertForbidden();
    foreach ([$case->status_id, $task->status_id] as $id) {
        $this->api('DELETE', $path.'/'.$id, headers: $headers)->assertConflict()->assertJsonPath('code', 'status_referenced');
    }
    $task->delete();
    $case->delete();
    foreach ([$case->status_id, $task->status_id] as $id) {
        $this->api('DELETE', $path.'/'.$id, headers: $headers)->assertConflict();
    }
});
