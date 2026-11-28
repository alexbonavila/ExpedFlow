<?php

use App\Models\Expedient;
use App\Models\Status;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\AuthorizationHttp;
use Tests\Support\RefreshGuardedMySql;

uses(AuthorizationHttp::class, RefreshGuardedMySql::class);

beforeEach(function () {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('global Kanban moves audit the shared status while two actors retain private preferences', function () {
    $case = Expedient::factory()->create();
    $destination = Status::factory()->create();
    $actor = User::factory()->create()->assignRole('user');
    $headers = $this->authorizationBearer($actor);
    $peer = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $path = "/api/v1/expedients/{$case->id}";

    $this->api('PATCH', $path.'/preferences', ['hidden' => true], $peer)->assertOk();
    $this->api('POST', $path.'/move', ['status_id' => $case->status_id], $headers)->assertOk();
    $this->api('POST', $path.'/move', ['status_id' => $destination->id], $headers)->assertOk();
    $this->api('GET', $path.'/preferences', headers: $peer)->assertOk()->assertJsonPath('data.hidden', true);

    $row = Activity::query()->sole();
    expect($row->event)->toBe('case.updated');
    expect($row->causer_id)->toBe($actor->id);
    expect($row->attribute_changes->get('old')['status_id'])->toBe($case->status_id);
    expect($row->attribute_changes->get('attributes')['status_id'])->toBe($destination->id);
});

test('tag membership audits sorted changes and deletion retains links to active and trashed cases', function () {
    $headers = $this->authorizationBearer($this->authorizationAdmin());
    $case = Expedient::factory()->create();
    $trashed = Expedient::factory()->create(['deleted_at' => now()]);
    $first = Tag::factory()->create();
    $second = Tag::factory()->create();
    $trashed->tags()->attach($first);
    $path = "/api/v1/expedients/{$case->id}/tags";

    $this->api('PUT', $path, ['tag_ids' => [$second->id, $first->id]], $headers)->assertOk();
    $this->api('PUT', $path, ['tag_ids' => [$first->id, $second->id]], $headers)->assertOk();
    $this->api('DELETE', "/api/v1/tags/{$first->id}", headers: $headers)->assertOk();

    $rows = Activity::query()->orderBy('id')->get();
    expect($rows->pluck('event')->all())->toBe(['case.tags_changed', 'tag.detached', 'tag.deleted']);
    expect($rows[0]->getProperty('metadata.added_ids'))->toBe([$first->id, $second->id]);
    expect($rows[1]->getProperty('metadata.case_ids'))->toBe([$case->id, $trashed->id]);
    expect($rows[1]->getProperty('operation_id'))->toBe($rows[2]->getProperty('operation_id'));
});

test('a status rename records cleared localized labels and a repeated rename has no audit', function () {
    $status = Status::factory()->create(['name' => 'Original', 'name_translations' => ['es' => 'Inicial', 'ca' => 'Inici']]);
    $headers = $this->authorizationBearer($this->authorizationAdmin());
    $path = "/api/v1/statuses/{$status->id}";
    $this->api('PATCH', $path, ['name' => 'Updated'], $headers)->assertOk();
    $this->api('PATCH', $path, ['name' => 'Updated'], $headers)->assertOk();

    $row = Activity::query()->sole();
    expect($row->event)->toBe('status.updated');
    expect($row->attribute_changes->get('old'))->toEqual(['name' => 'Original', 'name_translations' => ['es' => 'Inicial', 'ca' => 'Inici']]);
    expect($row->attribute_changes->get('attributes'))->toEqual(['name' => 'Updated', 'name_translations' => null]);
});
