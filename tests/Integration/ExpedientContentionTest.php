<?php

use App\Models\Client;
use App\Models\Expedient;
use App\Models\ExpedientSequence;
use App\Models\ExpedientType;
use App\Models\Task;
use App\Models\User;
use App\Support\AuthorizationCatalog;
use App\Support\ExpedientWriter;
use Carbon\CarbonImmutable;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
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
function commitAgainstCaseContender(array $operation, ?Closure $beforeCommit = null): array
{
    $process = new Process(['php', 'tests/Fixtures/CaseContender.php'], base_path(), ['APP_ENV' => 'testing']);
    $process->setTimeout(15)->setInput(json_encode($operation, JSON_THROW_ON_ERROR));
    $process->start();
    try {
        expect($process->waitUntil(fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "ready\n")))
            ->toBeTrue('The contender must reach the expected MySQL statement.');
        $beforeCommit?->__invoke();
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

test('parallel case creation waits on the same annual row on first use and at the final slot', function (int $previous): void {
    $clock = '2030-09-01 13:00:00 UTC';
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse($clock));
    $actor = User::factory()->create()->assignRole('user');
    $other = User::factory()->create()->assignRole('user');
    $headers = $this->authorizationBearer($other);
    $typeA = ExpedientType::factory()->create(['code' => '03']);
    $typeB = ExpedientType::factory()->create(['code' => '07']);
    $one = $this->caseInput(['expedient_type_id' => $typeA->id]);
    $two = $this->caseInput(['expedient_type_id' => $typeB->id]);
    if ($previous === 998) {
        ExpedientSequence::query()->forceCreate(['year' => 2030, 'last_number' => $previous]);
    }

    DB::beginTransaction();
    // Exercise the allocator directly so the peer can reach the annual row instead of the authorization lock.
    $winner = app(ExpedientWriter::class)->create($actor, $one);
    $result = commitAgainstCaseContender([
        'method' => 'POST', 'path' => '/api/v1/expedients', 'authorization' => $headers['Authorization'],
        'clock' => $clock, 'body' => $two, 'barrier' => 'insert into `expedient_sequences`',
    ]);

    expect($winner->code)->toBe($previous === 0 ? '203000103' : '203099903');
    if ($previous === 0) {
        expect($result['status'])->toBe(201)->and($result['data']['code'])->toBe('203000207');
        expect(Expedient::query()->where('year', 2030)->count())->toBe(2);
    } else {
        expect($result['status'])->toBe(409)->and($result['code'])->toBe('annual_sequence_exhausted');
        expect(Expedient::query()->where('year', 2030)->count())->toBe(1);
        expect(ExpedientSequence::query()->findOrFail(2030)->last_number)->toBe(999);
    }
})->with(['first use' => 0, 'last slot' => 998]);

test('competing case moves append to the same destination without losing either position', function (): void {
    $a = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $b = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $first = Expedient::factory()->create();
    $second = Expedient::factory()->create();
    $tail = Expedient::factory()->create(['column_position' => 14]);
    $move = ['status_id' => $tail->status_id];

    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();
    $result = commitAgainstCaseContender([
        'method' => 'PATCH', 'path' => "/api/v1/expedients/{$second->id}", 'authorization' => $b['Authorization'],
        'body' => $move, 'barrier' => 'for update',
    ], fn () => $this->api('PATCH', "/api/v1/expedients/{$first->id}", $move, $a)
        ->assertOk()->assertJsonPath('data.column_position', 15));
    expect($result['status'])->toBe(200)->and($result['data']['column_position'])->toBe(16);
    expect($tail->fresh()->column_position)->toBe(14);
});

test('MySQL resolves competing Unicode client inserts and the API reports the duplicate safely', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    DB::beginTransaction();
    $winner = Client::factory()->create(['name' => 'Pérfil']);
    $result = commitAgainstCaseContender([
        'method' => 'POST', 'path' => '/api/v1/clients', 'authorization' => $headers['Authorization'],
        'body' => ['name' => '  PERFIL  '], 'barrier' => 'insert into `clients`',
    ]);

    expect($result['status'])->toBe(409)->and($result['code'])->toBe('client_name_conflict');
    expect(Client::query()->sole()->is($winner))->toBeTrue();
});

test('a newly committed case reference prevents concurrent catalog deletion', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $client = Client::factory()->create();
    DB::beginTransaction();
    $case = Expedient::factory()->create(['requester_client_id' => $client->id]);

    $result = commitAgainstCaseContender([
        'method' => 'DELETE', 'path' => "/api/v1/clients/{$client->id}", 'authorization' => $headers['Authorization'],
        'barrier' => 'select * from `clients`',
    ]);

    expect($result['status'])->toBe(409)->and($result['code'])->toBe('client_referenced');
    $this->assertModelExists($client);
    $this->assertModelExists($case);
});

test('the case parent lock makes a contending child writer recheck the active parent', function (): void {
    $headers = $this->authorizationBearer(User::factory()->create()->assignRole('user'));
    $case = Expedient::factory()->create();
    $existing = Task::factory()->for($case)->create();
    DB::beginTransaction();
    $this->api('DELETE', "/api/v1/expedients/{$case->id}", headers: $headers)->assertOk();

    $result = commitAgainstCaseContender(['parent' => $case->id, 'barrier' => 'from `expedients`']);

    expect($result['status'])->toBe(404);
    expect(Task::withTrashed()->sole()->is($existing))->toBeTrue();
    expect($existing->fresh()->deleted_by_expedient)->toBeTrue();
});

test('an already authenticated case writer is denied after a concurrent credential or permission revocation', function (bool $token): void {
    $creator = Role::query()->create(['name' => 'Synthetic case creator', 'guard_name' => 'web']);
    $creator->givePermissionTo('expedients.create');
    $actor = User::factory()->create()->assignRole($creator);
    $headers = $this->authorizationBearer($actor);
    $admin = $this->authorizationBearer($this->authorizationAdmin());
    $input = $this->caseInput();
    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = commitAgainstCaseContender([
        'method' => 'POST', 'path' => '/api/v1/expedients', 'authorization' => $headers['Authorization'],
        'body' => $input, 'barrier' => 'for update',
    ], function () use ($token, $headers, $admin, $creator): void {
        if ($token) {
            $this->api('DELETE', '/api/v1/auth/token', headers: $headers)->assertOk();
        } else {
            $this->api('PUT', "/api/v1/roles/{$creator->id}/permissions", ['permissions' => []], $admin)->assertOk();
        }
    });

    expect($result['status'])->toBe($token ? 401 : 403)
        ->and($result['code'])->toBe($token ? 'unauthenticated' : 'forbidden');
    $this->assertDatabaseEmpty('expedients');
    $this->assertDatabaseEmpty('expedient_sequences');
})->with(['token revoked' => true, 'grant revoked' => false]);

test('an admitted SPA writer cannot commit after its database session is logged out', function (): void {
    $actor = User::factory()->create()->assignRole('user');
    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $actor->email, 'password' => 'password'])->assertOk();
    $session = DB::table('sessions')->where('user_id', $actor->id)->sole();
    $input = $this->caseInput();

    DB::beginTransaction();
    AuthorizationCatalog::lockAdministratorRole();

    $result = commitAgainstCaseContender([
        'method' => 'POST', 'path' => '/api/v1/expedients', 'body' => $input,
        'browser' => $this->caseBrowserCredentials(), 'barrier' => 'for update',
    ], fn () => $this->spa('POST', '/auth/logout')->assertOk());

    expect($result['status'])->toBe(401)->and($result['code'])->toBe('unauthenticated');
    $this->assertDatabaseEmpty('expedients');
    $this->assertDatabaseEmpty('expedient_sequences');
    $this->assertDatabaseMissing('sessions', ['id' => $session->id]);
});
