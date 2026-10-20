<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RiseTechApps\RiseTools\Features\HealthCheck\Doctor;
use RiseTechApps\RiseTools\Tests\TestCase;

class DoctorTest extends TestCase
{
    public function test_redis_is_skipped_when_the_app_does_not_use_it(): void
    {
        Artisan::call('risetools:doctor', ['--only' => '']);

        $this->assertStringNotContainsString('Redis', Artisan::output());
    }

    public function test_redis_runs_when_the_queue_uses_it(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default']]);
        // Sem servidor Redis aqui: basta o check aparecer (OK ou FAIL).
        config(['database.redis.default' => ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);

        Artisan::call('risetools:doctor');

        $this->assertStringContainsString('Redis', Artisan::output());
    }

    public function test_only_option_runs_the_requested_checks(): void
    {
        Artisan::call('risetools:doctor', ['--only' => 'database,cache']);
        $output = Artisan::output();

        $this->assertStringContainsString('Database', $output);
        $this->assertStringContainsString('Cache', $output);
        $this->assertStringNotContainsString('Storage', $output);
    }

    public function test_failed_jobs_are_counted(): void
    {
        DB::statement('CREATE TABLE failed_jobs (id INTEGER PRIMARY KEY, uuid TEXT, connection TEXT, queue TEXT, payload TEXT, exception TEXT, failed_at TEXT)');
        DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'c', 'queue' => 'q', 'payload' => '{}', 'exception' => 'e', 'failed_at' => now()]);
        config(['queue.failed' => ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs']]);
        $this->app->forgetInstance('queue.failer');

        $this->assertSame(1, Artisan::call('risetools:doctor', ['--only' => 'failed-jobs', '--max-failed-jobs' => 0]));
        $this->assertStringContainsString('1 failed job(s) (max 0)', Artisan::output());

        $this->assertSame(0, Artisan::call('risetools:doctor', ['--only' => 'failed-jobs', '--max-failed-jobs' => 1]));
    }

    public function test_invalid_only_option_fails(): void
    {
        $this->assertSame(1, Artisan::call('risetools:doctor', ['--only' => 'nope']));
    }

    public function test_json_output(): void
    {
        $code = Artisan::call('risetools:doctor', ['--only' => 'database,cache', '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(0, $code);
        $this->assertTrue($report['healthy']);
        $this->assertSame(['database', 'cache'], array_column($report['checks'], 'check'));
        $this->assertArrayHasKey('ms', $report['checks'][0]);
    }

    public function test_custom_check_via_api_and_config(): void
    {
        app(Doctor::class)->addCheck('Payments', fn(array $options) => 'gateway up');
        config(['risetools.health.checks' => ['broken' => FailingCheck::class]]);

        $this->assertSame(1, Artisan::call('risetools:doctor', ['--only' => 'payments,broken', '--json' => true]));
        $checks = collect(json_decode(Artisan::output(), true)['checks'])->keyBy('check');

        $this->assertTrue($checks['payments']['ok']);
        $this->assertSame('gateway up', $checks['payments']['detail']);
        $this->assertFalse($checks['broken']['ok']);
        $this->assertSame('provider down', $checks['broken']['detail']);
    }

    public function test_custom_checks_run_by_default(): void
    {
        app(Doctor::class)->addCheck('payments', fn() => 'gateway up');

        $this->assertContains('payments', app(Doctor::class)->defaultChecks());
    }

    public function test_scheduler_and_horizon_are_not_default_outside_production(): void
    {
        $defaults = app(Doctor::class)->defaultChecks();

        $this->assertNotContains('scheduler', $defaults);
        $this->assertNotContains('horizon', $defaults);
    }

    public function test_scheduler_heartbeat(): void
    {
        $this->assertSame(1, Artisan::call('risetools:doctor', ['--only' => 'scheduler']));
        $this->assertStringContainsString('No scheduler heartbeat yet', Artisan::output());

        $this->useHeartbeatStoreOnDisk();
        Doctor::heartbeatCache()->forever(Doctor::HEARTBEAT_KEY, time() - 60);
        $this->assertSame(0, Artisan::call('risetools:doctor', ['--only' => 'scheduler']));

        Doctor::heartbeatCache()->forever(Doctor::HEARTBEAT_KEY, time() - 20 * 60);
        $this->assertSame(1, Artisan::call('risetools:doctor', ['--only' => 'scheduler', '--scheduler-max-age' => 5]));
        $this->assertStringContainsString('Last heartbeat 20 min ago (max 5)', Artisan::output());
    }

    public function test_heartbeat_is_scheduled_and_writes_the_timestamp(): void
    {
        $this->useHeartbeatStoreOnDisk();

        $event = collect(app(Schedule::class)->events())->first(fn($event) => $event->description === 'risetools:scheduler-heartbeat');

        $this->assertNotNull($event, 'heartbeat not scheduled');
        $this->assertSame('* * * * *', $event->expression);

        $event->run($this->app);

        $this->assertEqualsWithDelta(time(), Doctor::heartbeatCache()->get(Doctor::HEARTBEAT_KEY), 5);
    }

    public function test_horizon_check_fails_when_not_installed(): void
    {
        if (interface_exists(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)) {
            $this->markTestSkipped('Horizon is installed.');
        }

        $this->assertSame(1, Artisan::call('risetools:doctor', ['--only' => 'horizon']));
        $this->assertStringContainsString('Laravel Horizon is not installed', Artisan::output());
    }

    /**
     * O heartbeat resolve o store direto (resolve()): com array, cada
     * resolução seria um store vazio.
     */
    private function useHeartbeatStoreOnDisk(): void
    {
        config([
            'cache.stores.health_file' => ['driver' => 'file', 'path' => $this->tempPath . '/cache'],
            'risetools.health.cache_store' => 'health_file',
        ]);
    }
}

class FailingCheck
{
    public function __invoke(array $options): string
    {
        throw new \RuntimeException('provider down');
    }
}
