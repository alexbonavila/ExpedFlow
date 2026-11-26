<?php

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
