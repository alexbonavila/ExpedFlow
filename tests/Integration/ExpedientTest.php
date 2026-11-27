<?php

use App\Models\Client;
use App\Models\Expedient;
use App\Models\ExpedientSequence;
use App\Models\ExpedientType;
use App\Models\Status;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\RefreshGuardedMySql;

uses(RefreshGuardedMySql::class);

test('cases retain both client roles and the originally assigned code on reclassification', function () {
    $sequence = ExpedientSequence::factory()->create(['year' => 2026]);
    $type = ExpedientType::factory()->create(['code' => '05']);
    $requester = Client::factory()->create();
    $represented = Client::factory()->create();
    $status = Status::factory()->create();

    $case = Expedient::factory()
        ->for($sequence, 'annualSequence')
        ->for($type, 'type')
        ->for($requester, 'requester')
        ->for($represented, 'representedClient')
        ->for($status)
        ->create()
        ->refresh();

    expect($case->code)->toBe('202600105');
    expect($case->type->code)->toBe('05');
    expect($case->annualSequence->is($sequence))->toBeTrue();
    expect($case->requester->is($requester))->toBeTrue();
    expect($case->representedClient->is($represented))->toBeTrue();
    expect($case->status->is($status))->toBeTrue();
    expect($case->description)->toBeNull();
    expect($case->column_version)->toBe(1);
    expect($requester->requestedExpedients()->sole()->is($case))->toBeTrue();
    expect($represented->representedExpedients()->sole()->is($case))->toBeTrue();
    expect($sequence->expedients()->sole()->is($case))->toBeTrue();
    expect($type->expedients()->sole()->is($case))->toBeTrue();
    expect($status->expedients()->sole()->is($case))->toBeTrue();

    $case->type()->associate(ExpedientType::factory()->create(['code' => '99']));
    $case->save();

    expect($case->refresh()->code)->toBe('202600105');
    expect($case->type->code)->toBe('99');
});

test('case code uniqueness includes the shared annual number and permits the same number in a new year', function () {
    $type = ExpedientType::factory()->create(['code' => '00']);
    $otherType = ExpedientType::factory()->create(['code' => '07']);
    $year = ExpedientSequence::factory()->create(['year' => 2026, 'last_number' => 999]);
    $nextYear = ExpedientSequence::factory()->create(['year' => 2027]);
    $case = Expedient::factory()->for($year, 'annualSequence')->for($type, 'type')->create(['sequence_number' => 999]);

    expect($case->code)->toBe('202699900');
    expect(fn () => Expedient::factory()->for($year, 'annualSequence')->for($type, 'type')->create(['sequence_number' => 999]))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => Expedient::factory()->for($year, 'annualSequence')->for($otherType, 'type')->create(['sequence_number' => 999]))
        ->toThrow(UniqueConstraintViolationException::class, 'expedients_year_sequence_number_unique');

    $otherCase = Expedient::factory()->for($nextYear, 'annualSequence')->for($type, 'type')->create();
    expect($otherCase->code)->toBe('202700100');
    expect($otherCase->representedClient)->toBeNull();
});

test('MySQL rejects malformed case codes, out-of-range sequences and mismatched prefixes', function (array $attributes) {
    $case = Expedient::factory()->create();

    expect(fn () => DB::table('expedients')->where('id', $case->id)->update($attributes))
        ->toThrow(QueryException::class, 'expedients_code_check');
})->with([
    'nondigit' => [['code' => '2026001AA']],
    'zero' => [['code' => '202600000', 'sequence_number' => 0]],
    'over limit' => [['code' => '202699900', 'sequence_number' => 1_000]],
    'inconsistent' => [['code' => '100000200', 'sequence_number' => 2]],
]);

test('MySQL enforces mandatory requester and status, a distinct represented client and existing annual row', function () {
    $case = Expedient::factory()->create();

    foreach (['requester_client_id', 'status_id'] as $field) {
        expect(fn () => DB::table('expedients')->where('id', $case->id)->update([$field => null]))
            ->toThrow(QueryException::class, $field);
    }
    expect(fn () => $case->forceFill(['represented_client_id' => $case->requester_client_id])->save())
        ->toThrow(QueryException::class, 'expedients_distinct_clients_check');

    expect(fn () => DB::table('expedients')->where('id', $case->id)->update(['year' => 1000, 'code' => '100000100']))
        ->toThrow(QueryException::class, 'expedients_year_foreign');
});

test('MySQL restricts catalog deletion while a restorable case references its rows', function () {
    $represented = Client::factory()->create();
    $case = Expedient::factory()->for($represented, 'representedClient')->create();
    $status = $case->status;
    $type = $case->type;
    $requester = $case->requester;
    $sequence = $case->annualSequence;
    $case->delete();

    foreach ([$requester, $represented, $type, $status, $sequence] as $catalog) {
        expect(fn () => $catalog->delete())->toThrow(QueryException::class);
    }

    $case->forceDelete();

    $this->assertModelExists($sequence);
    expect($sequence->refresh()->last_number)->toBe(1);
    expect($status->delete())->toBeTrue();
});

test('annual counter defaults to zero and cannot exceed 999', function () {
    DB::table('expedient_sequences')->insert(['year' => 2026]);

    expect(ExpedientSequence::query()->findOrFail(2026)->last_number)->toBe(0);
    expect(fn () => DB::table('expedient_sequences')->where('year', 2026)->update(['last_number' => 1_000]))
        ->toThrow(QueryException::class, 'expedient_sequences_range_check');
    expect(fn () => DB::table('expedient_sequences')->insert(['year' => 999]))
        ->toThrow(QueryException::class, 'expedient_sequences_range_check');
});

test('type codes contain two ASCII digits and are unique', function () {
    $type = ExpedientType::factory()->create(['code' => '00']);

    expect($type->refresh()->code)->toBe('00');
    expect(fn () => ExpedientType::factory()->create(['code' => '00']))->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => ExpedientType::factory()->create(['code' => '7']))->toThrow(QueryException::class, 'expedient_types_code_check');
    expect(fn () => ExpedientType::factory()->create(['code' => 'x7']))->toThrow(QueryException::class, 'expedient_types_code_check');
});

test('clients trim surrounding whitespace and ignore case and accents only for uniqueness', function () {
    $client = Client::factory()->create(['name' => "\u{00A0}Café  Services\t"]);

    expect($client->refresh()->name)->toBe('Café  Services');
    expect(fn () => Client::factory()->create(['name' => 'CAFE  SERVICES']))->toThrow(UniqueConstraintViolationException::class);
    expect(Client::factory()->create(['name' => 'Cafe Services'])->exists)->toBeTrue();
    expect(fn () => DB::table('clients')->insert(['name' => ' trailing ']))->toThrow(QueryException::class, 'clients_name_check');
    expect(fn () => Client::factory()->create(['name' => ' ']))->toThrow(QueryException::class, 'clients_name_check');
});

test('case write fields require explicit assignment by trusted code', function () {
    expect(fn () => new Expedient(['code' => '202600105', 'status_id' => 1]))
        ->toThrow(MassAssignmentException::class);
});
