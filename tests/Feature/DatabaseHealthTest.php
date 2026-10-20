<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\DatabaseHealthMonitor;
use RiseTechApps\RiseTools\Tests\TestCase;

class DatabaseHealthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, created_at TEXT, updated_at TEXT)');
        DB::statement('CREATE TABLE nopk (user_id INTEGER, label TEXT)');
    }

    public function test_driver_comes_from_the_connection_not_its_name(): void
    {
        // A conexão do TestCase se chama "testing": antes o driver virava "testing" e nenhum check rodava.
        $types = array_column((new DatabaseHealthMonitor())->run(), 'type');

        $this->assertContains('missing_primary_key', $types);
    }

    public function test_table_option_checks_only_that_table(): void
    {
        $report = $this->healthJson(['--table' => 'nopk']);

        $this->assertNotEmpty($report['issues']);
        $this->assertSame(['nopk'], array_values(array_unique(array_column($report['issues'], 'table'))));
    }

    public function test_json_output_respects_severity_and_summary(): void
    {
        $report = $this->healthJson(['--severity' => 'critical']);

        $this->assertSame(['critical'], array_values(array_unique(array_column($report['issues'], 'severity'))));
        $this->assertSame(count($report['issues']), $report['summary']['total_issues']);
        $this->assertSame(0, $report['summary']['info']);
        $this->assertSame('testing', $report['database']['connection']);
        $this->assertSame('sqlite', $report['database']['driver']);
    }

    public function test_export_respects_filters(): void
    {
        $path = $this->tempPath . '/report.json';

        Artisan::call('risetools:db-health', ['--table' => 'users', '--export' => $path]);
        $report = json_decode(file_get_contents($path), true);

        $this->assertSame('users', $report['issues'][0]['table'] ?? 'users');
        $this->assertNotContains('nopk', array_column($report['issues'], 'table'));
        $this->assertSame(count($report['issues']), $report['summary']['total_issues']);
    }

    public function test_invalid_severity_fails(): void
    {
        $this->assertSame(1, Artisan::call('risetools:db-health', ['--severity' => 'bogus']));
        $this->assertStringContainsString("Invalid severity 'bogus'", Artisan::output());
    }

    public function test_nullable_without_default_check_was_removed(): void
    {
        $types = array_column((new DatabaseHealthMonitor())->run(), 'type');

        $this->assertNotContains('nullable_without_default', $types);
    }

    public function test_unhealthy_database_exits_with_failure(): void
    {
        $this->assertSame(1, Artisan::call('risetools:db-health'));
    }

    private function healthJson(array $options): array
    {
        Artisan::call('risetools:db-health', $options + ['--json' => true]);

        $report = json_decode(Artisan::output(), true);
        $this->assertIsArray($report, '--json output must be valid JSON');

        return $report;
    }
}
