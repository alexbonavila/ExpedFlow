<?php

use App\Models\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Support\RefreshGuardedMySql;

uses(RefreshGuardedMySql::class);

test('pruning uses a strict leap-year UTC calendar cutoff across batches and preserves incidents', function () {
    $this->travelTo(CarbonImmutable::parse('2028-02-29 02:30:05', 'UTC'));
    $boundary = CarbonImmutable::parse('2027-02-28 02:30:05', 'UTC')->timestamp;
    $table = DB::table('activity_log');
    $row = fn (?int $epoch): array => [
        'description' => 'synthetic legacy entry',
        'created_at' => $epoch === null ? null : DB::raw('FROM_UNIXTIME('.$epoch.')'),
    ];
    $table->insert(array_fill(0, 501, $row($boundary - 1)));
    $exact = $table->insertGetId($row($boundary));
    $newer = $table->insertGetId($row($boundary + 1));
    $unknown = $table->insertGetId($row(null));
    $incident = Incident::factory()->create();

    $this->artisan('expedflow:audit-prune', ['--dry-run' => true])
        ->expectsOutput('Audit rows matched: 501. Deleted: 0.')
        ->expectsOutput('Audit rows with unknown dates retained: 1.')->assertSuccessful();
    expect($table->count())->toBe(504);
    $this->artisan('expedflow:audit-prune')->expectsOutput('Audit rows matched: 501. Deleted: 501.')->assertSuccessful();
    expect($table->orderBy('id')->pluck('id')->all())->toBe([$exact, $newer, $unknown]);
    $this->assertModelExists($incident);
    $this->artisan('expedflow:audit-prune')->expectsOutput('Audit rows matched: 0. Deleted: 0.')->assertSuccessful();
});

test('the UTC prune schedule registers one daily job and blocks a competing scheduled invocation', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains($event->command, 'expedflow:audit-prune'));
    expect($events)->toHaveCount(1);
    $event = $events->sole();
    expect($event->expression)->toBe('0 3 * * *');
    expect($event->timezone)->toBe('UTC');
    expect($event->withoutOverlapping)->toBeTrue();
    expect($event->expiresAt)->toBe(60);
    $mutex = app(CacheEventMutex::class);
    expect($mutex->create($event))->toBeTrue();
    try {
        expect($event->shouldSkipDueToOverlapping())->toBeTrue();
    } finally {
        $mutex->forget($event);
    }
});
