<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\HarnessJob;
use Tests\Fixtures\HarnessMail;
use Tests\Fixtures\HarnessReady;

test('an isolated scheduled task writes a fixture and dispatches its configured handoffs', function () {
    $disk = Storage::fake('local');
    Event::fake([HarnessReady::class]);
    Queue::fake([HarnessJob::class]);
    Mail::fake();
    $this->travelTo(now()->startOfMinute());

    Schedule::call(function () use ($disk): void {
        $disk->put('phase-b/ready.txt', 'ready');
        event(new HarnessReady('phase-b/ready.txt'));
        Queue::push(new HarnessJob('phase-b/ready.txt'));
        Mail::to('fixture@example.test')->send(new HarnessMail);
    })->name('Phase B fixture');

    $this->artisan('schedule:run')->assertSuccessful();
    $disk->assertExists('phase-b/ready.txt', 'ready');
    Event::assertDispatched(HarnessReady::class, fn (HarnessReady $event): bool => $event->path === 'phase-b/ready.txt');
    Queue::assertPushed(HarnessJob::class, fn (HarnessJob $job): bool => $job->path === 'phase-b/ready.txt');
    Mail::assertSent(HarnessMail::class, fn (HarnessMail $mail): bool => $mail->hasTo('fixture@example.test'));
});
