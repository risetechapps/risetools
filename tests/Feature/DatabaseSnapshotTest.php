<?php

namespace RiseTechApps\RiseTools\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Exceptions\ToolNotFoundException;
use RiseTechApps\RiseTools\Tests\TestCase;
use Symfony\Component\Process\ExecutableFinder;

class DatabaseSnapshotTest extends TestCase
{
    private string|false $originalPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPath = getenv('PATH');

        // Disco separado do 'local': o snapshot só pode usar a Storage API
        // (mesma situação de um S3).
        config([
            'filesystems.disks.snapshots_test' => ['driver' => 'local', 'root' => $this->tempPath . '/disk', 'throw' => false],
            'risetools.snapshot.disk' => 'snapshots_test',
            'risetools.snapshot.path' => 'snapshots',
        ]);
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);

        parent::tearDown();
    }

    public function test_sqlite_create_list_restore_delete(): void
    {
        $this->requiresExtension('sqlite3');
        $this->useSqliteFile();

        DB::statement('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
        DB::table('items')->insert(['name' => 'a']);

        $snapshot = new DatabaseSnapshot();
        $this->assertTrue($snapshot->create('base'));

        $this->assertFileExists($this->tempPath . '/disk/snapshots/base.sqlite');
        $this->assertTrue($snapshot->exists('base'));
        $this->assertTrue($snapshot->exists('base.sqlite'), 'name with extension (as the old list showed it)');
        $this->assertSame(['base'], array_column($snapshot->list(), 'name'));

        DB::table('items')->insert(['name' => 'b']);
        $snapshot->restore('base');
        DB::purge();

        $this->assertSame(['a'], DB::table('items')->pluck('name')->all());

        $this->assertTrue($snapshot->delete('base'));
        $this->assertFalse($snapshot->exists('base'));
        $this->assertFalse($snapshot->delete('base'));
    }

    public function test_temporary_files_are_removed(): void
    {
        $this->requiresExtension('sqlite3');
        $this->useSqliteFile();
        DB::statement('CREATE TABLE items (id INTEGER PRIMARY KEY)');

        $before = glob(sys_get_temp_dir() . '/risetools-snapshot-*') ?: [];
        $snapshot = new DatabaseSnapshot();
        $snapshot->create('tmp');
        $snapshot->restore('tmp');

        $this->assertSame($before, glob(sys_get_temp_dir() . '/risetools-snapshot-*') ?: []);
    }

    public function test_list_ignores_other_files(): void
    {
        mkdir($this->tempPath . '/disk/snapshots', 0777, true);
        file_put_contents($this->tempPath . '/disk/snapshots/notes.txt', 'x');

        $this->assertSame([], (new DatabaseSnapshot())->list());
    }

    public function test_restore_of_missing_snapshot_fails_in_english(): void
    {
        $this->expectExceptionMessage("Snapshot 'nope' not found");

        (new DatabaseSnapshot())->restore('nope');
    }

    public function test_missing_tool_is_reported_by_the_command(): void
    {
        $this->skipIfOnPath('pg_dump');
        $this->useConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'u', 'password' => 'p']);

        $code = Artisan::call('risetools:snapshot-create', ['name' => 'a']);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Tool not found: pg_dump (PostgreSQL client)', $output);
        $this->assertStringContainsString('apt-get install postgresql-client', $output);
    }

    public function test_missing_mysql_client_fails_before_truncating(): void
    {
        $this->skipIfOnPath('mysql');
        // Host inalcançável: se o restore tocasse no banco (truncate), o erro
        // seria de conexão, não ToolNotFoundException.
        $this->useConnection(['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'u', 'password' => 'p']);
        mkdir($this->tempPath . '/disk/snapshots', 0777, true);
        file_put_contents($this->tempPath . '/disk/snapshots/old.sql', "-- dump\n");

        try {
            (new DatabaseSnapshot())->restore('old');
            $this->fail('Expected ToolNotFoundException.');
        } catch (ToolNotFoundException $e) {
            $this->assertSame('mysql', $e->tool);
        }
    }

    public function test_tool_failure_shows_the_real_error_not_tool_not_found(): void
    {
        $this->fakeTool('pg_dump', 'pg_dump: error: connection to server failed: FATAL:  password authentication failed for user "u"');
        $this->useConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'u', 'password' => 'p']);

        $code = Artisan::call('risetools:snapshot-create', ['name' => 'a']);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('password authentication failed', $output);
        $this->assertStringNotContainsString('Tool not found', $output);
    }

    private function useSqliteFile(): void
    {
        $file = $this->tempPath . '/db.sqlite';
        touch($file);

        $this->useConnection(['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]);
    }

    private function useConnection(array $config): void
    {
        config(['database.connections.snapshot_test' => $config, 'database.default' => 'snapshot_test']);
        DB::purge('snapshot_test');
        // O comando resolve a classe pelo container (singleton): a config nova precisa valer.
        $this->app->forgetInstance(DatabaseSnapshot::class);
    }

    private function skipIfOnPath(string $binary): void
    {
        if ((new ExecutableFinder())->find($binary) !== null) {
            $this->markTestSkipped("{$binary} is installed on this machine.");
        }
    }

    /**
     * Coloca no PATH um executável com esse nome que escreve $stderr e sai com 1.
     */
    private function fakeTool(string $name, string $stderr): void
    {
        $bin = $this->tempPath . '/bin';
        mkdir($bin, 0777, true);

        if (PHP_OS_FAMILY === 'Windows') {
            file_put_contents("{$bin}/{$name}.bat", "@echo off\r\necho {$stderr} 1>&2\r\nexit /b 1\r\n");
            $bin = str_replace('/', '\\', $bin);
        } else {
            file_put_contents("{$bin}/{$name}", "#!/bin/sh\necho '{$stderr}' >&2\nexit 1\n");
            chmod("{$bin}/{$name}", 0755);
        }

        putenv('PATH=' . $bin . PATH_SEPARATOR . $this->originalPath);
    }
}
