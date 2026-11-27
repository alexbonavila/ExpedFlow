<?php

use App\Models\Expedient;
use App\Models\Incident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RefreshGuardedMySql;

uses(RefreshGuardedMySql::class);

test('case incidents keep their snapshot and an audit retains historical references after physical deletion', function () {
    $user = User::factory()->create();
    $case = Expedient::factory()->create(['samba_relative_path' => 'EXPEDIENTES/synthetic-case']);
    $incident = Incident::factory()->for($user)->forHistoricalExpedient($case)->create([
        'context' => ['stage' => 'copy'],
    ])->refresh();
    $audit = Activity::query()->create([
        'log_name' => 'synthetic',
        'description' => 'A fixture case was updated.',
        'event' => 'updated',
        'subject_type' => Expedient::class,
        'subject_id' => $case->id,
        'causer_type' => User::class,
        'causer_id' => $user->id,
        'attribute_changes' => ['old' => ['name' => 'Before'], 'attributes' => ['name' => 'After']],
    ]);

    expect($incident->context)->toBe(['stage' => 'copy']);
    expect($incident->created_at)->toBeInstanceOf(CarbonImmutable::class);
    expect($incident->user->is($user))->toBeTrue();
    expect($case->incidents()->sole()->is($incident))->toBeTrue();
    expect($user->incidents()->sole()->is($incident))->toBeTrue();
    expect($incident->toArray())->not->toHaveKey('context');
    expect($case->toArray())->not->toHaveKey('samba_relative_path');
    expect($audit->subject->is($case))->toBeTrue();
    expect($audit->causer->is($user))->toBeTrue();
    expect($audit->attribute_changes->get('old'))->toBe(['name' => 'Before']);
    expect(Schema::hasIndex('activity_log', ['created_at']))->toBeTrue();

    $case->delete();
    expect($incident->expedient->is($case))->toBeTrue();

    $case->forceDelete();
    $user->delete();

    $incident->refresh();
    expect($incident->expedient_id)->toBeNull();
    expect($incident->expedient)->toBeNull();
    expect($incident->user_id)->toBeNull();
    expect($incident->expedient_code)->toBe($case->code);
    expect($audit->refresh()->subject_id)->toBe($case->id);
    expect($audit->causer_id)->toBe($user->id);
    expect($audit->subject)->toBeNull();
    expect($audit->causer)->toBeNull();
});

test('standalone incidents allow an unknown case and reject malformed historical codes', function () {
    $incident = Incident::factory()->create()->refresh();

    expect($incident->expedient_id)->toBeNull();
    expect($incident->expedient_code)->toBeNull();
    expect($incident->user_id)->toBeNull();
    expect($incident->context)->toBeNull();
    expect(fn () => $incident->forceFill(['expedient_code' => 'not-code'])->save())
        ->toThrow(QueryException::class, 'incidents_expedient_code_check');
});
