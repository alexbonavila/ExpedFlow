<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use PDO;
use RuntimeException;

final class TestingDatabase
{
    public static function assertSafe(Application $app): void
    {
        $config = $app['config'];
        self::assertConfiguration(
            $app->environment(),
            $config->string('database.default'),
            $config->array('database.connections.mysql'),
        );

        /** @var Connection $connection */
        $connection = $app['db']->connection('mysql');
        $pdo = $connection->getPdo();
        $identity = $pdo->query("SELECT DATABASE(), SUBSTRING_INDEX(CURRENT_USER(), '@', 1)")->fetch(PDO::FETCH_NUM);

        if ($identity !== ['expedflow_test', 'expedtest']) {
            throw new RuntimeException('MySQL did not authenticate the dedicated testing account on the testing schema.');
        }

        $grants = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        $schema = (int) $pdo->query('SELECT @@partial_revokes')->fetchColumn() === 1
            ? 'expedflow_test'
            : 'expedflow\\_test';
        $schemaGrant = '/^GRANT [A-Z][A-Z ,]+ ON `'.preg_quote($schema, '/').'`\.\* TO /';
        $hasSchemaGrant = false;

        foreach ($grants as $grant) {
            if (str_contains($grant, ' WITH GRANT OPTION')) {
                throw new RuntimeException('The testing account must not have grant delegation.');
            }

            if (preg_match($schemaGrant, $grant) === 1) {
                $hasSchemaGrant = true;

                continue;
            }

            if (! str_starts_with($grant, 'GRANT USAGE ON *.* TO ')) {
                throw new RuntimeException('The testing account has privileges outside the dedicated testing schema.');
            }
        }

        if (! $hasSchemaGrant) {
            throw new RuntimeException('The testing account has no dedicated schema privileges.');
        }
    }

    /** @param array<string, mixed> $connection */
    public static function assertConfiguration(string $environment, string $default, array $connection): void
    {
        if (
            $environment !== 'testing'
            || $default !== 'mysql'
            || ($connection['driver'] ?? null) !== 'mysql'
            || ($connection['host'] ?? null) !== '127.0.0.1'
            || ($connection['database'] ?? null) !== 'expedflow_test'
            || ($connection['username'] ?? null) !== 'expedtest'
            || ! is_string($connection['password'] ?? null)
            || $connection['password'] === ''
            || ! in_array($connection['url'] ?? null, [null, ''], true)
            || ! in_array($connection['unix_socket'] ?? null, [null, ''], true)
            || isset($connection['read'])
            || isset($connection['write'])
        ) {
            throw new RuntimeException('Refusing database operations outside the dedicated MySQL testing connection.');
        }
    }
}
