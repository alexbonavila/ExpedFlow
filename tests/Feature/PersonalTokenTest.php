<?php

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\IdentityHttp;
use Tests\Support\RefreshGuardedMySql;

uses(IdentityHttp::class, RefreshGuardedMySql::class);

beforeEach(function () {
    $this->identityHttp();
});

test('the token endpoint returns a hashed and expiring per-client credential once', function () {
    $this->freezeTime();
    config(['identity.token_days' => 2]);
    $user = User::factory()->create();
    $issued = $this->api('POST', '/api/v1/auth/token', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'Synthetic handset', 'abilities' => ['*'],
    ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.expires_at', now()->startOfSecond()->addDays(2)->toISOString());
    $plaintext = $issued->json('data.token');
    $stored = $user->tokens()->sole();
    expect($stored->name)->toBe('Synthetic handset')
        ->and($stored->abilities)->toBe(['identity'])
        ->and($stored->token)->toBe(hash('sha256', Str::after($plaintext, '|')))
        ->and($stored->expires_at->toISOString())->toBe(now()->startOfSecond()->addDays(2)->toISOString());

    $this->api('GET', '/api/v1/me', headers: ['Authorization' => 'Bearer '.$plaintext])->assertOk()
        ->assertExactJson(['data' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'locale' => 'es',
            'active' => true, 'permissions' => [],
        ]]);
    $this->travel(3)->days();
    $this->api('GET', '/api/v1/me', headers: ['Authorization' => 'Bearer '.$plaintext])->assertUnauthorized();
});

test('bearer logout revokes exactly its own token and does not accept an SPA session', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $first = $user->createToken('First client');
    $second = $user->createToken('Second client');
    $independent = $other->createToken('Different account');

    $this->api('DELETE', '/api/v1/auth/token', ['id' => $independent->accessToken->id], [
        'Authorization' => 'Bearer '.$first->plainTextToken,
    ])->assertOk()->assertJsonPath('code', 'token_revoked');

    $this->api('GET', '/api/v1/me', headers: ['Authorization' => 'Bearer '.$first->plainTextToken])->assertUnauthorized();
    $this->api('GET', '/api/v1/me', headers: ['Authorization' => 'Bearer invalid|token'])->assertUnauthorized();
    foreach ([$second, $independent] as $valid) {
        $this->api('GET', '/api/v1/me', headers: ['Authorization' => 'Bearer '.$valid->plainTextToken])
            ->assertOk()->assertJsonPath('data.id', $valid->accessToken->tokenable_id);
    }
    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    $this->spa('DELETE', '/auth/token')->assertForbidden()->assertJsonPath('code', 'bearer_required');
    $this->spa('POST', '/auth/token', ['device_name' => 'No password'])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    $this->assertModelExists($second->accessToken);

    $this->api('POST', '/api/v1/auth/logout', headers: ['Authorization' => 'Bearer '.$second->plainTextToken])
        ->assertForbidden()->assertJsonPath('code', 'session_required');
});

test('disabled accounts cannot obtain a token or use an independently retained token', function () {
    $user = User::factory()->create(['disabled_at' => now()]);
    $fixture = $user->createToken('Synthetic stale client');
    $this->api('POST', '/api/v1/auth/token', [
        'email' => $user->email, 'password' => 'password', 'device_name' => 'Synthetic handset',
    ])->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    $this->api('GET', '/api/v1/me', headers: ['Authorization' => 'Bearer '.$fixture->plainTextToken])
        ->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    expect(PersonalAccessToken::query()->count())->toBe(1);
});
