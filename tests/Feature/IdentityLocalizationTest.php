<?php

use App\Models\User;
use App\Notifications\PasswordRecoveryLink;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;
use Tests\Support\IdentityHttp;
use Tests\Support\RefreshGuardedMySql;

uses(IdentityHttp::class, RefreshGuardedMySql::class);

beforeEach(function () {
    $this->identityHttp();
});

test('a user can change only their own locale and API codes have the same meaning in every language', function () {
    $user = User::factory()->create(['locale' => 'es']);
    $other = User::factory()->create(['locale' => 'en']);
    $auth = ['Authorization' => 'Bearer '.$user->createToken('Locale fixture')->plainTextToken];
    $response = $this->api('PATCH', '/api/v1/me/locale', ['locale' => 'ru'], $auth + ['Accept-Language' => 'en'])
        ->assertUnprocessable()->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('message', 'Revisa los campos indicados.')
        ->assertJsonPath('errors.locale.0', 'El valor de idioma no es válido.');
    $response->assertHeaderMissing('Location');

    foreach (['ca', 'en', 'es'] as $locale) {
        $this->api('PATCH', '/api/v1/me/locale', ['locale' => $locale, 'id' => $other->id, 'name' => 'Ignored'], $auth)
            ->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonPath('data.locale', $locale);
        expect($user->fresh()->locale)->toBe($locale);
    }
    expect($user->fresh()->name)->toBe($user->name);
    expect($other->fresh()->locale)->toBe('en');
    $this->api('PATCH', "/api/v1/users/{$other->id}", ['locale' => 'ca'], $auth)->assertForbidden();
    $this->api('PATCH', '/api/v1/me/locale', ['locale' => 'ca'])->assertUnauthorized();
});

test('request-specific language state is restored and unsupported languages use the fallback', function () {
    App::setLocale('en');
    $this->api('GET', '/api/v1/me', headers: ['Accept-Language' => 'ca-ES,es;q=0.8'])
        ->assertUnauthorized()->assertExactJson(['message' => 'Inicia la sessió per continuar.', 'code' => 'unauthenticated']);
    expect(app()->getLocale())->toBe('en');
    $this->api('GET', '/api/v1/me', headers: ['Accept-Language' => 'xx'])
        ->assertUnauthorized()->assertExactJson(['message' => 'Sign in to continue.', 'code' => 'unauthenticated']);
    $this->api('GET', '/api/v1/me', headers: ['Accept-Language' => 'es-ES'])
        ->assertUnauthorized()->assertExactJson(['message' => 'Inicia sesión para continuar.', 'code' => 'unauthenticated']);
    $this->api('GET', '/api/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Sign in to continue.');

    App::setLocale('xx');
    $this->api('GET', '/api/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Sign in to continue.');
    expect(app()->getLocale())->toBe('xx');
});

test('identity, validation and recovery mail translations have equivalent keys', function () {
    foreach (['identity.php', 'validation.php'] as $file) {
        $baseline = require lang_path('en/'.$file);
        foreach (['es', 'ca'] as $locale) {
            expect(array_keys(require lang_path($locale.'/'.$file)))->toBe(array_keys($baseline));
        }
    }
    foreach (['es', 'ca'] as $locale) {
        expect(array_keys(json_decode(file_get_contents(lang_path("$locale.json")), true)))
            ->toBe(array_keys(json_decode(file_get_contents(lang_path('en.json')), true)));
    }
});

test('recovery mail uses the recipient locale independently of the requested response language', function () {
    Notification::fake();
    config(['app.url' => 'https://trusted.example']);
    $user = User::factory()->create(['locale' => 'ca']);

    $this->api('POST', '/api/v1/auth/forgot-password', ['email' => $user->email], ['Accept-Language' => 'es'])
        ->assertOk()->assertJsonPath('message', 'Si la cuenta cumple los requisitos, recibirás un correo para restablecer la contraseña.');

    Notification::assertSentTo($user, PasswordRecoveryLink::class, function (PasswordRecoveryLink $link) use ($user): bool {
        expect($link->locale)->toBe('ca');
        App::setLocale($link->locale);
        $mail = $link->toMail($user);
        expect($mail->subject)->toBe('Restableix la contrasenya d’ExpedFlow');
        expect($mail->actionText)->toBe('Restablir la contrasenya');

        return true;
    });
});
