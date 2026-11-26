<?php

use App\Models\User;
use Illuminate\Support\Carbon;

test('the welcome page renders the application name', function () {
    $this->withoutVite();

    $this->get('/')
        ->assertOk()
        ->assertSee('<title>ExpedFlow</title>', escape: false);
});

test('the health endpoint reports that Laravel booted', function () {
    $this->get('/up')->assertOk();
});

test('an unknown API route returns a JSON 404 without an Accept header', function () {
    $this->get('/api/v1/missing')
        ->assertNotFound()
        ->assertJsonStructure(['message'])
        ->assertJsonMissingPath('trace');
});

test('the scaffold inspire command displays a quote', function () {
    $this->artisan('inspire')->expectsOutputToContain(' — ')->assertSuccessful();
});

test('the user exposes a verification timestamp without serializing authentication material', function () {
    $user = User::factory()->make([
        'email_verified_at' => '2026-01-01 10:30:00',
        'remember_token' => 'fixture-remember-token',
    ]);

    expect($user->email_verified_at)->toBeInstanceOf(Carbon::class);
    expect($user->toArray())->not->toHaveKey('password')->not->toHaveKey('remember_token');
});
