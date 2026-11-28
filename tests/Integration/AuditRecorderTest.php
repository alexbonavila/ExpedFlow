<?php

use App\Models\Client;
use App\Models\User;
use App\Support\Audit\AuditChanges;
use App\Support\Audit\AuditContext;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditRecorder;
use App\Support\Audit\AuditSubject;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\RefreshGuardedMySql;

uses(RefreshGuardedMySql::class);

test('the package stores the actual actor, case-independent subject, shared operation and UTC instant', function () {
    $actor = User::factory()->create();
    $account = User::factory()->create(['name' => 'Jane', 'email' => 'private-before@example.test']);
    $context = AuditContext::authenticated($actor);
    $recorder = app(AuditRecorder::class);
    $minimum = time();

    DB::transaction(function () use ($recorder, $context, $account): void {
        $before = AuditSubject::User->snapshot($account);
        $account->forceFill(['name' => 'Jane P', 'email' => 'private-after@example.test'])->save();
        $recorder->record($context, AuditEvent::UserUpdated, $account, $before, AuditSubject::User->snapshot($account));
        $recorder->record($context, AuditEvent::UserTokenRevoked, $account);
    });
    $account->delete();
    $actor->delete();

    $rows = Activity::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2);
    expect($rows[0]->event)->toBe('user.updated');
    expect($rows[0]->log_name)->toBe('security');
    expect($rows[0]->causer_id)->toBe($actor->id);
    expect($rows[0]->subject_id)->toBe($account->id);
    expect($rows[0]->subject_type)->toBe(User::class);
    expect($rows[0]->attribute_changes->all())->toEqual([
        'old' => ['name' => 'Jane'],
        'attributes' => ['name' => 'Jane P', 'email' => ['changed' => true]],
    ]);
    expect($rows[0]->getProperty('operation_id'))->toBe($context->operationId);
    expect($rows[1]->getProperty('operation_id'))->toBe($context->operationId);
    expect($rows[1]->created_at->timestamp)->toBeGreaterThanOrEqual($minimum)->toBeLessThanOrEqual(time());
    expect(json_encode(DB::table('activity_log')->get()))->not->toContain('private-before', 'private-after', $account->password);
});

test('a cancellation of the audit insert rolls back the domain mutation', function () {
    $client = Client::factory()->create(['name' => 'Before']);
    $context = AuditContext::bootstrap();
    Activity::saving(fn () => false);

    expect(fn () => DB::transaction(function () use ($client, $context): void {
        $client->forceFill(['name' => 'After'])->save();
        app(AuditRecorder::class)->record($context, AuditEvent::ClientUpdated, $client, ['name' => 'Before'], ['name' => 'After']);
    }))->toThrow(RuntimeException::class, 'The audit entry could not be saved.');

    expect($client->refresh()->name)->toBe('Before');
    expect(Activity::query()->count())->toBe(0);
});

test('an audit rolls back with its business transaction and a committed no-op does not add a row', function () {
    $account = User::factory()->create();
    $context = AuditContext::passwordRecovery($account);
    $recorder = app(AuditRecorder::class);

    expect(fn () => DB::transaction(function () use ($recorder, $context, $account): void {
        $recorder->record($context, AuditEvent::UserRolesChanged, $account, ['roles' => [1]], ['roles' => [2]]);
        throw new RuntimeException('Synthetic rollback.');
    }))->toThrow(RuntimeException::class, 'Synthetic rollback.');

    DB::transaction(function () use ($recorder, $context, $account): void {
        $recorder->record($context, AuditEvent::UserRolesChanged, $account, ['roles' => [1, 2]], ['roles' => [2, 1, 2]]);
    });
    expect(Activity::query()->count())->toBe(0);
});

test('unknown fields and invalid allowlisted values cannot be persisted', function () {
    expect(fn () => AuditChanges::between(AuditSubject::User, [], ['remember_token' => 'private-sentinel']))
        ->toThrow(LogicException::class);
    expect(fn () => AuditChanges::between(AuditSubject::User, [], ['roles' => ['private-sentinel']]))
        ->toThrow(LogicException::class);
    expect(fn () => AuditChanges::between(AuditSubject::Client, [], ['name' => str_repeat('a', 256)]))
        ->toThrow(LogicException::class);
});
