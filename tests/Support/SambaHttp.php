<?php

namespace Tests\Support;

use Database\Seeders\AuthorizationSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;

trait SambaHttp
{
    use CaseHttp;

    protected function prepareStandaloneSambaHttp(bool $initializeDatabase): void
    {
        TestingDatabase::assertSafe($this->app);

        if ($initializeDatabase) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            $this->artisan('db:seed', ['--class' => AuthorizationSeeder::class, '--no-interaction' => true])->assertSuccessful();
        }

        $this->identityHttp();
    }

    /** @param array<string, mixed> $files @param array<string, string> $headers @param array<string, mixed> $parameters */
    protected function sambaMultipart(int $caseId, array $files, array $headers, array $parameters = [], bool $browser = false): TestResponse
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        Auth::shouldUse('web');
        $this->app->forgetInstance('session.store');
        $this->app['session']->forgetDrivers();
        $server = [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=expedflow-fixture',
            'HTTP_ORIGIN' => $browser ? 'https://expedflow.example' : '',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        foreach ($headers as $key => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
        }

        return $this->call('POST', "https://expedflow.example/api/v1/expedients/$caseId/uploads",
            parameters: $parameters, cookies: $browser ? $this->caseBrowserCredentials()['cookies'] : [],
            files: $files, server: $server);
    }
}
