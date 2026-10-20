<?php

namespace RiseTechApps\RiseTools\Tests\Feature\Postgres;

use Illuminate\Support\Facades\DB;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\DatabaseHealthMonitor;
use RiseTechApps\RiseTools\Tests\PostgresTestCase;

class PostgresHealthTest extends PostgresTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('DROP SCHEMA IF EXISTS rt_a CASCADE');
        DB::statement('DROP SCHEMA IF EXISTS rt_b CASCADE');
        DB::statement('CREATE SCHEMA rt_a');
        DB::statement('CREATE SCHEMA rt_b');

        DB::statement('CREATE TABLE rt_a.items (id serial PRIMARY KEY, a int, b int, c text)');
        DB::statement('CREATE INDEX idx_ab ON rt_a.items (a, b)');
        DB::statement('CREATE INDEX idx_ba ON rt_a.items (b, a)');
        DB::statement('CREATE INDEX idx_a1 ON rt_a.items (a)');
        DB::statement('CREATE INDEX idx_a2 ON rt_a.items (a)');
        DB::statement('CREATE INDEX idx_a_partial ON rt_a.items (a) WHERE c IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX uq_c ON rt_a.items (c)');
        DB::statement('CREATE TABLE rt_a.nopk (x int)');

        // Mesma tabela em outro schema, com colunas diferentes: não pode vazar.
        DB::statement('CREATE TABLE rt_b.items (id serial PRIMARY KEY, other_id int)');

        DB::statement('SET search_path TO rt_a');
    }

    protected function tearDown(): void
    {
        // $this->app é null quando o teste foi pulado antes de subir o app.
        if ($this->app) {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA IF EXISTS rt_a CASCADE');
            DB::statement('DROP SCHEMA IF EXISTS rt_b CASCADE');
        }

        parent::tearDown();
    }

    public function test_only_tables_of_the_current_schema(): void
    {
        $tables = array_values(array_unique(array_column((new DatabaseHealthMonitor())->run(), 'table')));
        sort($tables);

        $this->assertSame(['items', 'nopk'], $tables);
    }

    public function test_missing_primary_key(): void
    {
        $issues = $this->issuesFor('nopk');

        $this->assertArrayHasKey('missing_primary_key', $issues);
        $this->assertArrayNotHasKey('missing_primary_key', $this->issuesFor('items'));
    }

    public function test_duplicates_respect_column_order_and_skip_partial_indexes(): void
    {
        $duplicates = $this->issuesFor('items')['duplicate_indexes']['details'] ?? [];
        $pairs = array_map(fn($d) => $d['indexes'], $duplicates);

        $this->assertCount(1, $pairs, json_encode($pairs));
        $this->assertEqualsCanonicalizing(['idx_a1', 'idx_a2'], $pairs[0]);
    }

    public function test_unused_indexes_never_include_unique_or_primary(): void
    {
        $unused = $this->issuesFor('items')['unused_indexes']['details'] ?? [];

        $this->assertNotContains('uq_c', $unused);
        $this->assertNotContains('items_pkey', $unused);
        $this->assertContains('idx_ab', $unused);
    }

    public function test_columns_of_another_schema_do_not_leak(): void
    {
        // rt_b.items tem other_id sem índice; rt_a.items não tem coluna _id.
        $this->assertArrayNotHasKey('unindexed_foreign_keys', $this->issuesFor('items'));
    }

    /**
     * @return array<string, array> type => issue
     */
    private function issuesFor(string $table): array
    {
        $issues = [];

        foreach ((new DatabaseHealthMonitor())->runForTable($table) as $issue) {
            $issues[$issue['type']] = $issue;
        }

        return $issues;
    }
}
