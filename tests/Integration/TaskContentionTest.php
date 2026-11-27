<?php

use App\Models\Expedient;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Support\AuthorizationCatalog;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\CaseHttp;
use Tests\Support\TestingDatabase;

uses(CaseHttp::class);

beforeEach(function (): void {
    TestingDatabase::assertSafe($this->app);
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->seed(AuthorizationSeeder::class);
    $this->identityHttp();
});

afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/** @param array<string, mixed> $operation @return array<string, mixed> */
function commitAgainstTaskContender(array $operation, Closure $winner): array
{
    $process = new Process(['php', 'tests/Fixtures/CaseContender.php'], base_path(), ['APP_ENV' => 'testing']);
    $process->setTimeout(15)->setInput(json_encode($operation + ['barrier' => 'for update'], JSON_THROW_ON_ERROR));
    try {
        $process->start();
        expect($process->waitUntil(fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "ready\n")))
            ->toBeTrue('The contender must reach the expected MySQL statement.');
        $winner();
        DB::commit();
        expect($process->wait())->toBe(0, $process->getErrorOutput());
        expect(preg_match('/^result:(.+)$/m', $process->getOutput(), $matches))->toBe(1, $process->getOutput());

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        $process->stop();
    }
}

test('a second task writer sees committed ranks after a contending move', function (bool $create): void {
    $one = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $two = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $case = Expedient::factory()->create();
    $dest = Status::factory()->create();
    $task = Task::factory()->for($case)->create();
    $peer = Task::factory()->for($case)->create();
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = commitAgainstTaskContender([
        'method' => 'POST', 'path' => $create ? "/api/v1/expedients/{$case->id}/tasks" : "/api/v1/tasks/{$peer->id}/move",
        'authorization' => $two['Authorization'], 'body' => ['status_id' => $dest->id, 'name' => 'Concurrent task'],
    ], fn () => $this->api('POST', "/api/v1/tasks/{$task->id}/move", ['status_id' => $dest->id], $one)
        ->assertOk()->assertJsonPath('data.position', 1));
    expect($result['status'])->toBe($create ? 201 : 200)->and($result['data']['position'])->toBe(2);
    expect($case->tasks()->where('status_id', $dest->id)->orderBy('position')->pluck('position')->all())->toBe([1, 2]);
})->with(['create' => true, 'move' => false]);
