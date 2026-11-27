<?php

use App\Jobs\SendPasswordRecovery;
use App\Models\User;
use App\Notifications\PasswordRecoveryLink;
use App\Support\IdentityInput;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\IdentityHttp;
use Tests\Support\RefreshGuardedMySql;

uses(IdentityHttp::class, RefreshGuardedMySql::class);

beforeEach(function () {
    $this->identityHttp();
    config(['app.url' => 'https://trusted.example/', 'mail.default' => 'smtp']);
});

test('recovery has one public response and only delivers a trusted link to an eligible account', function () {
    Notification::fake();
    $active = User::factory()->create(['email' => 'recovery+fixture@example.test', 'locale' => 'ca']);
    $disabled = User::factory()->create(['disabled_at' => now()]);
    $expected = ['message' => 'If the account is eligible, a password recovery email will be sent.', 'code' => 'recovery_requested'];

    foreach ([$active->email, $disabled->email, 'unknown@example.test'] as $email) {
        $this->api('POST', '/api/v1/auth/forgot-password', ['email' => $email])
            ->assertOk()->assertExactJson($expected);
    }
    $this->api('POST', '/api/v1/auth/forgot-password', ['email' => $active->email])->assertOk();

    Notification::assertSentToTimes($active, PasswordRecoveryLink::class, 1);
    Notification::assertNotSentTo($disabled, PasswordRecoveryLink::class);
    Notification::assertSentTo($active, PasswordRecoveryLink::class, function (PasswordRecoveryLink $notice, array $channels) use ($active): bool {
        $mail = $notice->toMail($active);
        $url = parse_url($mail->actionUrl);
        parse_str($url['fragment'], $query);
        expect($channels)->toBe(['mail'])
            ->and($notice->locale)->toBe('ca')
            ->and($url['scheme'])->toBe('https')
            ->and($url['host'])->toBe('trusted.example')
            ->and($url['path'])->toBe('/reset-password')
            ->and($mail->outroLines[0])->toBe('This link expires after 60 minutes.')
            ->and($url)->not->toHaveKey('query')
            ->and($query['email'])->toBe($active->email)
            ->and(Password::broker()->tokenExists($active, $query['token']))->toBeTrue();

        return true;
    });
    $this->assertDatabaseCount('password_reset_tokens', 1);
});

test('the recovery endpoint enqueues the same work for known and unknown identities', function () {
    Queue::fake();
    $user = User::factory()->create();
    $this->api('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    $this->api('POST', '/api/v1/auth/forgot-password', ['email' => 'UNKNOWN@example.test'])->assertOk();

    Queue::assertPushed(SendPasswordRecovery::class, 2);
    Queue::assertPushed(SendPasswordRecovery::class, fn ($job): bool => $job->email === 'unknown@example.test');
    $this->assertDatabaseEmpty('password_reset_tokens');
});

test('mail transport failure and an insecure recovery origin expose no account details', function () {
    Log::spy();
    $user = User::factory()->create();
    $this->mock(MailFactory::class)->shouldReceive('mailer')->once()->andThrow(new TransportException('Synthetic transport failure'));
    $response = $this->api('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])
        ->assertOk()->assertJsonMissingPath('trace')->assertJsonPath('code', 'recovery_requested');

    Log::shouldHaveReceived('warning')->with('Password recovery mail delivery failed.')->once();
    $this->assertDatabaseEmpty('password_reset_tokens');

    config(['app.url' => 'http://trusted.example']);
    $this->api('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->assertExactJson($response->json());
    Log::shouldHaveReceived('warning')->with('Password recovery requires a valid HTTPS application URL.')->once();
    $this->assertDatabaseEmpty('password_reset_tokens');

    foreach (['https://', 'https://fixture@trusted.example/', 'https://trusted.example/?other=value'] as $unsafeOrigin) {
        config(['app.url' => $unsafeOrigin]);
        $this->api('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    }
    $this->assertDatabaseEmpty('password_reset_tokens');
});

test('a usable password reset consumes the token and revokes the old database session and all bearer tokens', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    $oldSession = DB::table('sessions')->where('user_id', $user->id)->sole()->id;
    $mobile = $user->createToken('Old device');
    $otherToken = $other->createToken('Independent device');
    $token = Password::broker()->createToken($user);
    $password = '  synthetic password ñ  ';
    $reset = ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password];

    Event::fake([PasswordReset::class]);
    $this->api('POST', '/api/v1/auth/reset-password', $reset)->assertOk()->assertJsonPath('code', 'password_reset')->assertJsonStructure(['code', 'message']);
    Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event): bool => $event->user->is($user));
    expect(Hash::check($password, $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->remember_token)->toBeNull()
        ->and($user->fresh()->auth_version)->toBe(1);
    $this->assertDatabaseMissing('sessions', ['id' => $oldSession]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    $this->assertModelMissing($mobile->accessToken);
    $this->assertModelExists($otherToken->accessToken);
    $this->spa('GET', '/me')->assertUnauthorized();
    $this->api('GET', '/api/v1/me', headers: ['Authorization' => 'Bearer '.$mobile->plainTextToken])->assertUnauthorized();
    $this->api('POST', '/api/v1/auth/reset-password', $reset)->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
    $this->freshCsrf();
    $this->spa('POST', '/auth/login', ['email' => $user->email, 'password' => $password])->assertOk();
});

test('reset rejects expired, mismatched, disabled and unknown credentials', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $disabled = User::factory()->create(['disabled_at' => now()]);
    $token = Password::broker()->createToken($user);
    $disabledToken = Password::broker()->createToken($disabled);
    $password = 'synthetic replacement';
    $input = ['password' => $password, 'password_confirmation' => $password];
    foreach ([[$other->email, $token], [$disabled->email, $disabledToken], ['unknown@example.test', $token]] as [$email, $issued]) {
        $this->api('POST', '/api/v1/auth/reset-password', $input + ['email' => $email, 'token' => $issued])
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
    }
    $this->travel(61)->minutes();
    $this->api('POST', '/api/v1/auth/reset-password', $input + ['email' => $user->email, 'token' => $token])
        ->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
    expect($user->fresh()->password)->toBe($user->password);
});

test('recovery rejects invalid reset input and rate limits normalized identities', function () {
    Queue::fake();
    $this->api('POST', '/api/v1/auth/reset-password', [
        'email' => 'reset@example.test', 'password' => str_repeat('ñ', 37), 'password_confirmation' => 'mismatch',
    ])->assertUnprocessable()->assertJsonValidationErrors(['token', 'password']);

    for ($request = 0; $request < 5; $request++) {
        $this->api('POST', '/api/v1/auth/forgot-password', ['email' => '  recovery@example.test '])->assertOk();
    }
    $this->api('POST', '/api/v1/auth/forgot-password', ['email' => 'RECOVERY@example.test'])->assertTooManyRequests()
        ->assertJsonPath('code', 'rate_limited');
    Queue::assertPushed(SendPasswordRecovery::class, 5);
});

test('a log mailer cannot create a recovery token or receive the recovery message', function () {
    Log::spy();
    Notification::fake();
    $user = User::factory()->create();
    config(['mail.default' => 'log']);

    $this->api('POST', '/api/v1/auth/forgot-password', ['email' => $user->email])
        ->assertOk()->assertJsonPath('code', 'recovery_requested');
    $this->assertDatabaseEmpty('password_reset_tokens');
    Notification::assertNothingSent();
    Log::shouldHaveReceived('warning')->with('Password recovery requires an SMTP mailer.')->once();
});

test('password changes enforce the exact bcrypt byte boundary and keep valid spaces', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);
    $base = ['email' => $user->email, 'token' => $token];
    $limit = str_repeat('é', 35).' x';
    $oversized = $limit.'x';

    $this->api('POST', '/api/v1/auth/reset-password', $base + ['password' => $oversized, 'password_confirmation' => $oversized])
        ->assertUnprocessable()->assertJsonPath('errors.password.0', 'The password must not exceed 72 bytes with bcrypt.');
    $this->api('POST', '/api/v1/auth/reset-password', $base + ['password' => $limit, 'password_confirmation' => $limit])
        ->assertOk();

    expect(Hash::check($limit, $user->fresh()->password))->toBeTrue();
});

test('recovery and reset validate their own inputs before issuing work or touching a token', function () {
    Queue::fake();
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);
    foreach ([null, 'invalid', str_repeat('a', 247).'@example.test'] as $invalid) {
        $this->api('POST', '/api/v1/auth/forgot-password', ['email' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('email');
    }
    $valid = ['email' => $user->email, 'token' => $token, 'password' => 'synthetic reset value', 'password_confirmation' => 'synthetic reset value'];
    foreach ([['email' => 'invalid'], ['token' => 123], ['token' => str_repeat('x', 256)], ['password' => 'short']] as $invalid) {
        $this->api('POST', '/api/v1/auth/reset-password', $invalid + $valid)->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));
    }
    Queue::assertNothingPushed();
    expect(Password::broker()->tokenExists($user, $token))->toBeTrue();
});

test('the new password length limit remains explicit for a non-bcrypt hasher', function () {
    config(['hashing.driver' => 'argon2id']);
    $rules = ['password' => IdentityInput::newPassword()];

    expect(Validator::make(['password' => str_repeat('x', 73)], $rules)->passes())->toBeTrue();
    expect(Validator::make(['password' => str_repeat('x', 1025)], $rules)->fails())->toBeTrue();
});
