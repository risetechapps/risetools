<?php

namespace RiseTechApps\RiseTools\Tests;

/**
 * PostgreSQL de verdade. Só roda com RISETOOLS_TEST_PGSQL=1 (o CI sobe um
 * serviço postgres); credenciais em DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/
 * DB_PASSWORD (padrões no phpunit.xml).
 */
abstract class PostgresTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('RISETOOLS_TEST_PGSQL') !== '1') {
            $this->markTestSkipped('PostgreSQL tests are disabled (set RISETOOLS_TEST_PGSQL=1).');
        }

        parent::setUp();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Nome diferente do driver de propósito: o driver precisa vir da config.
        $app['config']->set('database.default', 'pg_test');
        $app['config']->set('database.connections.pg_test', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'risetools_test'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', 'postgres'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }
}
