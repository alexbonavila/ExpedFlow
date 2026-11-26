<?php

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;

test('validation middleware rejects an invalid harness request with a JSON 422', function () {
    Route::post('/api/v1/_phase-b/validate', function (Request $request): JsonResponse {
        return response()->json($request->validate(['label' => ['required', 'string']]));
    });

    $this->postJson('/api/v1/_phase-b/validate', ['label' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('label');
    $this->postJson('/api/v1/_phase-b/validate', ['label' => 'Fixture'])
        ->assertOk()
        ->assertExactJson(['label' => 'Fixture']);
});

test('the web guard rejects an anonymous JSON request and recognizes an authenticated user', function () {
    $url = '/api/v1/_phase-b/identity';
    Route::get($url, fn (Request $request): JsonResponse => response()->json([
        'email' => $request->user()?->email,
    ]))->middleware('auth:web');
    $user = User::factory()->make(['email' => 'fixture@example.test']);

    $this->getJson($url)->assertUnauthorized();
    $this->actingAs($user, 'web')->getJson($url)
        ->assertOk()
        ->assertExactJson(['email' => 'fixture@example.test']);
});

test('the browser harness is registered only in testing', function () {
    $this->withoutVite()->get('/_phase-b')->assertOk()->assertSee('ExpedFlow test harness');

    $process = new Process(
        [PHP_BINARY, 'artisan', 'route:list', '--path=_phase-b', '--json', '--no-interaction'],
        base_path(),
        ['APP_ENV' => 'production', 'APP_DEBUG' => 'false'],
    );
    $process->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([]);
});
