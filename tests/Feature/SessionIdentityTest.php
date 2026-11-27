<?php

use App\Models\User;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\Support\IdentityHttp;
use Tests\Support\RefreshGuardedMySql;

uses(IdentityHttp::class, RefreshGuardedMySql::class);

beforeEach(function () {
    $this->identityHttp();
});

test('a real browser session rotates identifiers at login and ends at logout', function () {
    $user = User::factory()->create(['name' => 'Synthetic User', 'email' => 'spa@example.test']);
    $input = ['email' => '  SPA@example.test ', 'password' => 'password'];
    $csrf = $this->freshCsrf()->assertHeader('X-Frame-Options', 'DENY');
    $cookie = collect($csrf->headers->getCookies())->keyBy(fn ($value) => $value->getName());
    $sessionCookie = $cookie->get(config('session.cookie'));
    expect($sessionCookie->isHttpOnly())->toBeTrue()
        ->and($sessionCookie->isSecure())->toBeTrue()
        ->and($sessionCookie->getSameSite())->toBe('lax')
        ->and($cookie->get('XSRF-TOKEN')->isHttpOnly())->toBeFalse();
    $anonymous = DB::table('sessions')->sole()->id;

    $this->api('POST', '/api/v1/auth/login', $input, browser: true)->assertStatus(419)->assertJsonPath('code', 'csrf_mismatch');
    $this->api('POST', '/api/v1/auth/login', $input, ['X-XSRF-TOKEN' => 'invalid'], browser: true)->assertStatus(419);
    $this->spa('POST', '/auth/login', $input)->assertOk()->assertExactJson(['data' => [
        'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
        'locale' => 'es', 'active' => true, 'permissions' => [],
    ]]);
    $id = DB::table('sessions')->where('user_id', $user->id)->sole()->id;
    expect($id)->not->toBe($anonymous);
    $this->assertDatabaseMissing('sessions', ['id' => $anonymous]);

    $this->spa('GET', '/me')->assertOk()->assertJsonPath('data.id', $user->id);
    $oldToken = session()->token();
    $mobile = $user->createToken('Synthetic client')->accessToken;
    Event::fake([CurrentDeviceLogout::class]);
    $this->spa('POST', '/auth/logout')->assertOk()->assertJsonPath('code', 'logged_out');
    Event::assertDispatched(CurrentDeviceLogout::class);
    expect(session()->token())->toBeString()->not->toBeEmpty()->not->toBe($oldToken);
    $this->assertDatabaseMissing('sessions', ['id' => $id]);
    $this->assertModelExists($mobile);
    $this->spa('GET', '/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

test('anonymous, cross-origin and invalid credential requests are rejected safely', function () {
    $eligible = User::factory()->create();
    $disabled = User::factory()->create(['disabled_at' => now()]);
    $this->freshCsrf();

    $this->api('GET', '/api/v1/me')->assertUnauthorized()->assertHeaderMissing('Location')->assertJsonMissingPath('trace');
    $this->api('POST', '/api/v1/auth/login', ['email' => $eligible->email, 'password' => 'password'], [
        'Origin' => 'https://outside.example',
    ])->assertForbidden()->assertJsonPath('code', 'session_required')->assertHeaderMissing('Access-Control-Allow-Origin');
    $this->api('POST', '/api/v1/auth/register')->assertNotFound()->assertJsonPath('code', 'not_found');

    foreach ([[$eligible->email, 'wrong'], [$disabled->email, 'password'], ['unknown@example.test', 'password']] as [$email, $password]) {
        $this->spa('POST', '/auth/login', compact('email', 'password'))->assertUnauthorized()
            ->assertExactJson(['message' => 'The email or password is incorrect.', 'code' => 'invalid_credentials']);
    }
    $this->spa('GET', '/me')->assertUnauthorized();
});

test('the normalized identity and IP login ceiling returns JSON with retry information', function () {
    $this->freshCsrf();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->spa('POST', '/auth/login', ['email' => ' account@example.test ', 'password' => 'invalid'])->assertUnauthorized();
    }

    $this->spa('POST', '/auth/login', ['email' => 'ACCOUNT@example.test', 'password' => 'invalid'])
        ->assertTooManyRequests()->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
});

test('a legitimate old password can sign in and its hash is upgraded', function () {
    $old = password_hash('old', PASSWORD_BCRYPT, ['cost' => 4]);
    $user = User::factory()->create(['password' => $old]);
    Hash::setRounds(5);
    $this->freshCsrf();

    $this->spa('POST', '/auth/login', ['email' => $user->email, 'password' => 'old'])->assertOk();
    expect($user->fresh()->password)->not->toBe($old);
    expect(Hash::check('old', $user->fresh()->password))->toBeTrue();
});

test('non-testing deployments require HTTPS for identity requests', function () {
    $this->get('http://expedflow.example/api/v1/me')->assertForbidden()->assertJsonPath('code', 'https_required')->assertHeader('Cache-Control', 'no-store, private');
    $this->api('PUT', '/api/v1/me')->assertMethodNotAllowed()->assertJsonPath('code', 'method_not_allowed');
});

test('credential attempts share an IP-wide limit across login and token issuance', function () {
    config(['identity.limits.attempts_per_ip' => 2, 'identity.limits.attempts_per_identity' => 1]);
    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => 'first@example.test', 'password' => 'synthetic input'])
        ->assertUnauthorized();
    $this->api('POST', '/api/v1/auth/token', ['email' => 'second@example.test', 'password' => 'synthetic input', 'device_name' => 'Fixture'])
        ->assertUnauthorized();
    $this->spa('POST', '/auth/login', ['email' => 'third@example.test', 'password' => 'synthetic input'])
        ->assertTooManyRequests()->assertJsonPath('code', 'rate_limited');
});

test('SPA logout clears only the active session and retains unrelated remembered state', function () {
    $user = User::factory()->create();
    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    $this->spa('POST', '/auth/logout')->assertOk();
    expect($user->fresh()->remember_token)->toBe($user->remember_token);
});

test('rate limit floors protect each IP and each normalized email and IP pair', function (array $limits) {
    config($limits);
    $input = ['email' => 'first@example.test', 'password' => 'synthetic password', 'device_name' => 'Fixture'];

    $this->api('POST', '/api/v1/auth/token', $input, ip: '192.0.2.10')->assertUnauthorized();
    $this->api('POST', '/api/v1/auth/token', $input, ip: '192.0.2.10')->assertTooManyRequests();
    $this->api('POST', '/api/v1/auth/token', $input, ip: '192.0.2.11')->assertUnauthorized();
})->with([
    'IP floor' => [['identity.limits.attempts_per_ip' => 0, 'identity.limits.attempts_per_identity' => 5]],
    'identity floor' => [['identity.limits.attempts_per_ip' => 30, 'identity.limits.attempts_per_identity' => 0]],
]);

test('the credential routes validate each input field independently', function (array $invalid, string $field) {
    $user = User::factory()->create();
    $valid = ['email' => $user->email, 'password' => 'password', 'device_name' => 'Fixture'];
    $this->api('POST', '/api/v1/auth/token', $invalid + $valid)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseEmpty('personal_access_tokens');
})->with([
    'missing email' => [['email' => null], 'email'],
    'malformed email' => [['email' => 'bad address'], 'email'],
    'long email' => [['email' => str_repeat('a', 247).'@example.test'], 'email'],
    'missing password' => [['password' => null], 'password'],
    'non-string password' => [['password' => 1234], 'password'],
    'oversize password' => [['password' => str_repeat('x', 4097)], 'password'],
    'missing client name' => [['device_name' => null], 'device_name'],
    'non-string client name' => [['device_name' => 1234], 'device_name'],
    'long client name' => [['device_name' => str_repeat('a', 101)], 'device_name'],
]);
