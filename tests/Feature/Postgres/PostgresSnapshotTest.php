<?php

namespace RiseTechApps\RiseTools\Tests\Feature\Postgres;

use Illuminate\Support\Facades\DB;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;
use RiseTechApps\RiseTools\Tests\PostgresTestCase;
use Symfony\Component\Process\ExecutableFinder;

class PostgresSnapshotTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['pg_dump', 'psql'] as $tool) {
            if ((new ExecutableFinder())->find($tool) === null) {
                $this->markTestSkipped("{$tool} is not installed.");
            }
        }

        config([
            'filesystems.disks.snapshots_test' => ['driver' => 'local', 'root' => $this->tempPath . '/disk', 'throw' => false],
            'risetools.snapshot.disk' => 'snapshots_test',
        ]);

        DB::statement('DROP TABLE IF EXISTS public.snap_items');
        DB::statement('CREATE TABLE public.snap_items (id serial PRIMARY KEY, name text)');
        DB::table('snap_items')->insert(['name' => 'a']);
    }

    protected function tearDown(): void
    {
        // $this->app é null quando o teste foi pulado antes de subir o app.
        if ($this->app) {
            DB::statement('DROP TABLE IF EXISTS public.snap_items');
        }

        parent::tearDown();
    }

    public function test_clean_dump_restores_the_saved_state(): void
    {
        $snapshot = new DatabaseSnapshot();
        $snapshot->create('pg');

        $dump = file_get_contents($this->tempPath . '/disk/snapshots/pg.sql');
        $this->assertStringContainsString('DROP TABLE IF EXISTS public.snap_items', $dump);

        DB::table('snap_items')->insert(['name' => 'b']);
        $snapshot->restore('pg');
        DB::purge();

        $this->assertSame(['a'], DB::table('snap_items')->pluck('name')->all());
    }

    public function test_failed_restore_rolls_back_and_keeps_the_data(): void
    {
        // Começa com DROP (dump --clean), depois quebra: a transação inteira volta.
        $this->writeDump('broken', "DROP TABLE IF EXISTS public.snap_items;\nTHIS IS NOT SQL;\n");

        try {
            (new DatabaseSnapshot())->restore('broken');
            $this->fail('Expected the restore to fail.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('psql restore failed', $e->getMessage());
        }

        DB::purge();
        $this->assertSame(['a'], DB::table('snap_items')->pluck('name')->all());
    }

    public function test_legacy_dump_truncates_before_loading(): void
    {
        // Sem DROP: snapshot antigo. Limpa os dados e carrega o que o dump tem.
        $this->writeDump('legacy', "INSERT INTO public.snap_items (name) VALUES ('legacy');\n");

        (new DatabaseSnapshot())->restore('legacy');
        DB::purge();

        $this->assertSame(['legacy'], DB::table('snap_items')->pluck('name')->all());
    }

    private function writeDump(string $name, string $sql): void
    {
        @mkdir($this->tempPath . '/disk/snapshots', 0777, true);
        file_put_contents($this->tempPath . "/disk/snapshots/{$name}.sql", $sql);
    }
}
