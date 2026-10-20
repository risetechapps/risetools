<?php

namespace RiseTechApps\RiseTools\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase as BaseTestCase;
use RiseTechApps\RiseTools\Features\NPlusOneDetector\NPlusOneDetector;
use RiseTechApps\RiseTools\RiseToolsServiceProvider;

/**
 * SQLite em memória, cache array e uma pasta temporária por teste (apagada no
 * fim). Testes de PostgreSQL usam o PostgresTestCase.
 */
abstract class TestCase extends BaseTestCase
{
    /** Pasta temporária deste teste. */
    protected string $tempPath;

    protected function setUp(): void
    {
        $this->tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'risetools_test_' . getmypid() . '_' . uniqid();
        mkdir($this->tempPath, 0777, true);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Estado estático: não pode passar de um teste para o outro.
        NPlusOneDetector::disable();

        parent::tearDown();

        // Teste pulado antes do setUp (ex.: PostgreSQL desligado) não criou a pasta.
        if (isset($this->tempPath)) {
            (new Filesystem())->deleteDirectory($this->tempPath);
        }
    }

    protected function getPackageProviders($app): array
    {
        return [RiseToolsServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['RiseTools' => \RiseTechApps\RiseTools\RiseToolsFacade::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('broadcasting.default', 'null');
    }

    /**
     * Troca o request corrente (o Device lê IP e user agent dele).
     */
    protected function withRequest(array $server = [], string $uri = '/'): Request
    {
        $request = Request::create($uri, 'GET', [], [], [], $server);
        $this->app->instance('request', $request);

        return $request;
    }

    protected function requiresExtension(string $extension): void
    {
        if (!extension_loaded($extension)) {
            $this->markTestSkipped("Extension {$extension} is not loaded.");
        }
    }
}
