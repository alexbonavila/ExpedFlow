<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

test('each supported locale translates the same harness keys', function () {
    $expected = ['label', 'ready'];

    foreach (['en', 'es', 'ca'] as $locale) {
        expect(array_keys(require lang_path("$locale/testing.php")))->toEqualCanonicalizing($expected);
    }

    App::setLocale('ca');
    expect(__('testing.ready'))->toBe('A punt');
    expect(__('testing_fallback.message'))->toBe('Fallback example');
});

test('uses the installation timezone with ISO timestamps expressed in UTC', function () {
    $this->travelTo(Carbon::parse('2026-01-01T00:30:00+01:00'));

    expect(config('app.timezone'))->toBe('Europe/Madrid');
    expect(now()->timezoneName)->toBe('Europe/Madrid');
    expect(now()->toISOString())->toBe('2025-12-31T23:30:00.000000Z');
});
