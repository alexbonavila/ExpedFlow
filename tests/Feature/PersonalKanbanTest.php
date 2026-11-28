<?php

use App\Models\Expedient;
use App\Models\ExpedientUserPreference;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\Support\CaseHttp;
use Tests\Support\RefreshGuardedMySql;

uses(CaseHttp::class, RefreshGuardedMySql::class);

beforeEach(function (): void {
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

test('personal placement and hiding remain private across global moves and new cases', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $other = User::factory()->create()->assignRole('user');
    $a = $this->authorizationBearer($actor);
    $b = $this->authorizationBearer($other);
    $admin = $this->authorizationBearer($this->authorizationAdmin());
    $source = Status::factory()->create(['position' => 1]);
    $destination = Status::factory()->create(['position' => 2]);
    $unrelated = Status::factory()->create(['position' => 3]);
    $moving = Expedient::factory()->create(['status_id' => $source->id, 'column_position' => 1]);
    $same = Expedient::factory()->create(['status_id' => $source->id, 'column_position' => 2]);
    $first = Expedient::factory()->create(['status_id' => $destination->id, 'column_position' => 1]);
    $second = Expedient::factory()->create(['status_id' => $destination->id, 'column_position' => 2]);
    $outside = Expedient::factory()->create(['status_id' => $unrelated->id]);
    $task = Task::factory()->create(['expedient_id' => $moving->id, 'status_id' => $source->id]);
    $path = '/api/v1/expedients';
    $move = "$path/{$moving->id}/move";
    $preference = "$path/{$moving->id}/preferences";
    $ids = fn (array $headers, int $column): array => array_column($this->api('GET', "$path/board", headers: $headers)
        ->assertOk()->assertJsonMissingPath('links')->json("data.$column.expedients"), 'id');

    $this->api('GET', $preference, headers: $a)->assertExactJson([
        'data' => ['expedient_id' => $moving->id, 'position' => null, 'hidden' => false],
    ]);
    $this->api('POST', $move, ['status_id' => $source->id], $a)->assertOk()->assertJsonPath('data.position', 2);
    expect($ids($a, 0))->toBe([$same->id, $moving->id])
        ->and($ids($b, 0))->toBe([$moving->id, $same->id])
        ->and($moving->fresh()->column_version)->toBe(1)
        ->and($moving->fresh()->column_position)->toBe(1);
    $this->api('PATCH', $preference, ['hidden' => true], $a)->assertJsonPath('data.position', 2)->assertJsonPath('data.hidden', true);
    $this->api('PATCH', $preference, ['hidden' => false], $a)->assertJsonPath('data.position', 2)->assertJsonPath('data.hidden', false);
    $this->api('PATCH', $preference, ['hidden' => true], $b)->assertOk();
    $this->api('GET', "$path/{$moving->id}", headers: $b)->assertOk();
    $this->api('GET', $path, headers: $b)->assertOk()->assertJsonCount(5, 'data');
    $this->api('POST', "$path/{$second->id}/move", ['status_id' => $destination->id, 'position' => 1], $b)->assertOk();
    $this->api('POST', "$path/{$outside->id}/move", ['status_id' => $unrelated->id, 'position' => 1], $a)->assertOk();
    $outsideRank = ExpedientUserPreference::query()->where('expedient_id', $outside->id)->sole()->getAttributes();

    $this->api('POST', $move, ['status_id' => $destination->id, 'position' => 2], $a)->assertOk()
        ->assertJsonPath('data.status_id', $destination->id)->assertJsonPath('data.hidden', false);
    expect($ids($a, 1))->toBe([$first->id, $moving->id, $second->id])
        ->and($ids($b, 1))->toBe([$second->id, $first->id, $moving->id]);
    $this->api('GET', $preference, headers: $b)->assertJsonPath('data.hidden', true)->assertJsonPath('data.position', null);
    $this->api('GET', "$path/board", headers: $b)->assertJsonPath('data.1.expedients.2.hidden', true);
    expect($moving->fresh()->column_version)->toBe(2)->and($moving->fresh()->column_position)->toBe(3)
        ->and(ExpedientUserPreference::query()->where('expedient_id', $outside->id)->sole()->getAttributes())->toBe($outsideRank);
    expect($task->fresh()->status_id)->toBe($source->id)->and($task->fresh()->position)->toBe($task->position);

    $new = $this->api('POST', $path, $this->caseInput(['status_id' => $destination->id]), $a)->assertCreated()->json('data.id');
    expect($ids($a, 1))->toBe([$first->id, $moving->id, $second->id, $new])
        ->and($ids($b, 1))->toBe([$second->id, $first->id, $moving->id, $new]);
    $this->api('POST', $move, ['status_id' => $source->id, 'position' => 1], $a)->assertOk();
    expect($ids($a, 0))->toBe([$moving->id, $same->id])->and($ids($b, 0))->toBe([$same->id, $moving->id]);
    $this->api('PATCH', "$path/{$moving->id}", ['status_id' => $destination->id], $b)->assertOk();
    expect($ids($a, 1))->toBe([$first->id, $second->id, $new, $moving->id]);
    $this->api('PUT', '/api/v1/statuses/order', ['status_ids' => [$destination->id, $source->id, $unrelated->id]], $admin)->assertOk();
    expect($ids($a, 0))->toBe([$first->id, $second->id, $new, $moving->id]);
    expect($task->fresh()->status_id)->toBe($source->id);
});

test('board reads reconcile ties and stale versions and preserve preferences across case lifecycle', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $headers = $this->authorizationBearer($actor);
    $admin = $this->authorizationBearer($this->authorizationAdmin());
    $status = Status::factory()->create();
    [$first, $second, $third, $unranked] = array_map(fn (int $position): Expedient => Expedient::factory()
        ->create(['status_id' => $status->id, 'column_position' => $position, 'column_version' => 2]),
        [1, 1, 3, 2]);
    foreach ([[$third, 2, false], [$second, 2, true], [$first, 1, true]] as [$case, $version, $hidden]) {
        ExpedientUserPreference::factory()->create([
            'user_id' => $actor->id, 'expedient_id' => $case->id, 'position' => 1, 'column_version' => $version, 'hidden' => $hidden,
        ]);
    }
    $path = '/api/v1/expedients';
    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->api('GET', "$path/board", headers: $headers)->assertOk()
        ->assertJsonMissingPath('data.0.expedients.0.column_version')->assertJsonMissingPath('data.0.expedients.0.user_id')
        ->assertJsonPath('data.0.expedients.2.hidden', true);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queryCount)->toBeLessThan(15);
    expect(array_column($response->json('data.0.expedients'), 'id'))->toBe([$second->id, $third->id, $first->id, $unranked->id]);
    $this->assertDatabaseCount('expedient_user_preferences', 3);
    $casePath = "$path/{$second->id}";
    $this->api('DELETE', $casePath, headers: $headers)->assertOk();
    $this->api('GET', "$casePath/preferences", headers: $headers)->assertNotFound();
    $this->api('PATCH', "$casePath/preferences", ['hidden' => false], $headers)->assertNotFound();
    $this->api('GET', "$path/board", headers: $headers)->assertJsonPath('data.0.expedients.0.id', $third->id);
    $this->api('POST', "$casePath/restore", headers: $headers)->assertOk();
    $this->api('GET', "$path/board", headers: $headers)->assertJsonPath('data.0.expedients.0.id', $second->id)
        ->assertJsonPath('data.0.expedients.0.hidden', true);
    $this->api('DELETE', $casePath, headers: $headers)->assertOk();
    $this->api('POST', "$casePath/force-delete", headers: $admin)->assertOk();
    $this->assertDatabaseMissing('expedient_user_preferences', ['expedient_id' => $second->id]);
});

test('personal board authorization and invalid cross moves leave global and personal state intact', function (): void {
    $role = Role::create(['name' => 'Personal board editor', 'guard_name' => 'web']);
    $role->givePermissionTo('expedients.view', 'kanban.manage');
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole($role));
    $case = Expedient::factory()->create(['column_version' => PHP_INT_MAX]);
    $other = Status::factory()->create();
    $path = "/api/v1/expedients/{$case->id}";
    $this->api('POST', "$path/move", ['status_id' => $case->status_id], $headers)->assertOk();
    $this->api('POST', "$path/move", ['status_id' => $other->id], $headers)->assertForbidden();
    $this->api('PATCH', "$path/preferences", ['hidden' => 1], $headers)->assertUnprocessable();
    foreach (['position', 'user_id', 'column_version', 'status_id'] as $field) {
        $this->api('PATCH', "$path/preferences", ['hidden' => true, $field => 1], $headers)->assertUnprocessable();
    }
    $role->givePermissionTo('expedients.update');
    $this->api('POST', "$path/move", ['status_id' => 999999], $headers)->assertUnprocessable()->assertJsonValidationErrors('status_id');
    $this->api('POST', "$path/move", ['status_id' => $other->id, 'position' => 2], $headers)->assertUnprocessable()
        ->assertJsonValidationErrors('position');
    $this->api('POST', "$path/move", ['status_id' => $other->id], $headers)->assertConflict()->assertJsonPath('code', 'case_order_exhausted');
    $this->api('GET', "$path/preferences", headers: $headers)->assertJsonPath('data.position', 1)->assertJsonPath('data.hidden', false);
    expect($case->fresh()->status_id)->toBe($case->status_id);
    $this->assertDatabaseCount('expedient_user_preferences', 1);
});
