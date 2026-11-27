<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

trait IdentityHttp
{
    /** @var array<string, string> */
    private array $browserCookies = [];

    protected function identityHttp(): void
    {
        config(['sanctum.stateful' => ['expedflow.example'], 'session.driver' => 'database', 'session.secure' => true]);
        $this->app->instance('env', 'production');
    }

    /** @param array<string, mixed> $input @param array<string, string> $headers */
    protected function api(string $method, string $path, array $input = [], array $headers = [], bool $browser = false, string $ip = '127.0.0.1'): TestResponse
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        Auth::shouldUse('web');
        $this->app->forgetInstance('session.store');
        $this->app['session']->forgetDrivers();

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
            'HTTP_ORIGIN' => $browser ? 'https://expedflow.example' : '',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $result = $this->call(
            $method,
            'https://expedflow.example'.$path,
            cookies: $browser ? $this->browserCookies : [],
            server: $server,
            content: json_encode($input, JSON_THROW_ON_ERROR),
        );

        if ($browser) {
            foreach ($result->headers->getCookies() as $cookie) {
                $this->browserCookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $input */
    protected function spa(string $method, string $path, array $input = []): TestResponse
    {
        return $this->api($method, '/api/v1'.$path, $input, [
            'X-XSRF-TOKEN' => $this->browserCookies['XSRF-TOKEN'] ?? '',
        ], browser: true);
    }

    protected function freshCsrf(): TestResponse
    {
        return $this->api('GET', '/sanctum/csrf-cookie', browser: true)->assertNoContent();
    }
}
