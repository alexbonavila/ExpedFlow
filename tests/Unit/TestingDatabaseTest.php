<?php

use Tests\Support\TestingDatabase;

test('destructive test setup rejects an unsafe connection before connecting', function (string $environment, string $default, array $override) {
    $connection = array_replace([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'expedflow_test',
        'username' => 'expedtest',
        'password' => 'synthetic-test-password',
    ], $override);

    expect(fn () => TestingDatabase::assertConfiguration($environment, $default, $connection))
        ->toThrow(RuntimeException::class, 'Refusing database operations');
})->with([
    'production environment' => ['production', 'mysql', []],
    'incorrect default connection' => ['testing', 'sqlite', []],
    'incorrect driver' => ['testing', 'mysql', ['driver' => 'sqlite']],
    'remote host' => ['testing', 'mysql', ['host' => 'example.invalid']],
    'development schema' => ['testing', 'mysql', ['database' => 'expedflow']],
    'development account' => ['testing', 'mysql', ['username' => 'expedflow']],
    'empty password' => ['testing', 'mysql', ['password' => '']],
    'URL precedence' => ['testing', 'mysql', ['url' => 'mysql://example.invalid/expedflow']],
    'socket override' => ['testing', 'mysql', ['unix_socket' => '/tmp/mysql.sock']],
    'write override' => ['testing', 'mysql', ['write' => ['database' => 'expedflow']]],
    'read override' => ['testing', 'mysql', ['read' => ['host' => 'example.invalid']]],
]);
