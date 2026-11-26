<?php

use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

it('protects pages and JSON errors with a fresh response nonce', function () {
    $response = $this->get('/')->assertOk();
    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Security-Policy-Report-Only');

    $policy = (string) $response->headers->get('Content-Security-Policy');
    expect(preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $match))->toBe(1);
    expect($policy)->toContain(
        "default-src 'self'",
        "style-src 'self' 'nonce-{$match[1]}'",
        "object-src 'none'",
        "base-uri 'none'",
        "frame-ancestors 'none'",
        "form-action 'self'",
    )->not->toContain("'unsafe-inline'", "'unsafe-eval'");
    expect($response->getContent())->toContain('nonce="'.$match[1].'"');

    $error = $this->getJson('/api/v1/missing')->assertNotFound()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($error->headers->get('Content-Security-Policy'))->not->toContain($match[1]);
});

it('reports CSP only for the local Vite hot server', function () {
    $hotFile = tempnam(sys_get_temp_dir(), 'expedflow-vite-');

    try {
        file_put_contents($hotFile, 'http://127.0.0.1:5173');
        app(Vite::class)->useHotFile($hotFile);
        $this->app->instance('env', 'local');
        $this->get('/up')->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('Content-Security-Policy-Report-Only');

        $this->app->instance('env', 'production');
        $this->get('/up')->assertOk()
            ->assertHeader('Content-Security-Policy')
            ->assertHeaderMissing('Content-Security-Policy-Report-Only');
    } finally {
        unlink($hotFile);
    }
});

it('keeps the session cookie private while issuing a usable XSRF cookie over HTTPS', function () {
    config(['session.secure' => true]);
    $this->withoutVite();
    $response = $this->get('https://expedflow.example/')->assertOk();
    $cookies = collect($response->headers->getCookies())->keyBy(fn (Cookie $cookie): string => $cookie->getName());
    $session = $cookies->get(config('session.cookie'));
    $xsrf = $cookies->get('XSRF-TOKEN');

    expect($session)->toBeInstanceOf(Cookie::class)
        ->and($session->isSecure())->toBeTrue()
        ->and($session->isHttpOnly())->toBeTrue()
        ->and($session->getSameSite())->toBe('lax');
    expect($xsrf)->toBeInstanceOf(Cookie::class)
        ->and($xsrf->isSecure())->toBeTrue()
        ->and($xsrf->isHttpOnly())->toBeFalse()
        ->and($xsrf->getSameSite())->toBe('lax')
        ->and($xsrf->getValue())->not->toBe('');
    expect($xsrf->getValue())->not->toBe(session()->token());

    $this->app->instance('env', 'production');
    Route::post('/_phase-b/csrf-fixture', fn (): Response => response()->noContent())->middleware('web');
    $url = 'https://expedflow.example/_phase-b/csrf-fixture';
    $this->withUnencryptedCookie($session->getName(), $session->getValue())
        ->post($url)->assertStatus(419);
    $this->post($url, [], ['X-XSRF-TOKEN' => $xsrf->getValue()])->assertNoContent();
});
