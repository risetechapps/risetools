<?php

namespace RiseTechApps\RiseTools\Features\DatabaseHealthMonitor;

use Illuminate\Support\Facades\DB;

class DatabaseHealthMonitor
{
    private array $checks = [];
    private array $results = [];
    private string $driver;

    public function __construct()
    {
        $this->driver = config('database.default');
        $this->registerDefaultChecks();
    }

    /**
     * Executa todas as verificações de saúde.
     */
    public function run(): array
    {
        $this->results = [];
        $tables = $this->getAllTables();

        foreach ($tables as $table) {
            foreach ($this->checks as $check) {
                $result = $check($table);
                if ($result) {
                    $this->results[] = $result;
                }
            }
        }

        return $this->results;
    }

    public function runForTable(string $tableName): array
    {
        $this->results = [];

        foreach ($this->checks as $check) {
            $result = $check($tableName);
            if ($result) {
                $this->results[] = $result;
            }
        }

        return $this->results;
    }

    /**
     * Registra uma verificação personalizada.
     */
    public function addCheck(callable $check): self
    {
        $this->checks[] = $check;
        return $this;
    }

    /**
     * Retorna estatísticas resumidas.
     */
    public function getSummary(): array
    {
        $critical = count(array_filter($this->results, fn($r) => $r['severity'] === 'critical'));
        $warning = count(array_filter($this->results, fn($r) => $r['severity'] === 'warning'));
        $info = count(array_filter($this->results, fn($r) => $r['severity'] === 'info'));

        return [
            'total_issues' => count($this->results),
            'critical' => $critical,
            'warning' => $warning,
            'info' => $info,
            'healthy' => $critical === 0 && $warning === 0,
        ];
    }

    /**
     * Gera relatório formatado.
     */
    public function generateReport(): array
    {
        return [
            'timestamp' => now()->toDateTimeString(),
            'database' => [
                'driver' => $this->driver,
                'connection' => config("database.connections.{$this->driver}.database"),
            ],
            'summary' => $this->getSummary(),
            'issues' => $this->results,
        ];
    }

    private function registerDefaultChecks(): void
    {
        // Verifica tabelas sem chave primária
        $this->checks[] = function (string $table) {
            if (!$this->hasPrimaryKey($table)) {
                return [
                    'table' => $table,
                    'type' => 'missing_primary_key',
                    'severity' => 'critical',
                    'message' => "Table '{$table}' has no primary key",
                    'suggestion' => "Add an auto-increment 'id' or UUID column as a primary key",
                ];
            }
            return null;
        };

        // Verifica índices duplicados
        $this->checks[] = function (string $table) {
            $duplicates = $this->findDuplicateIndexes($table);
            if (!empty($duplicates)) {
                return [
                    'table' => $table,
                    'type' => 'duplicate_indexes',
                    'severity' => 'warning',
                    'message' => "Duplicate indexes found in '{$table}'",
                    'details' => $duplicates,
                    'suggestion' => 'Remove redundant indexes to save space and improve INSERT/UPDATE performance',
                ];
            }
            return null;
        };

        // Verifica colunas JSON em tabelas grandes (MySQL)
        $this->checks[] = function (string $table) {
            if ($this->driver !== 'mysql') return null;

            $rowCount = $this->getTableRowCount($table);
            if ($rowCount > 1000000) { // 1M+ rows
                $jsonColumns = $this->getJsonColumns($table);
                if (!empty($jsonColumns)) {
                    return [
                        'table' => $table,
                        'type' => 'json_in_large_table',
                        'severity' => 'warning',
                        'message' => "Table '{$table}' with {$rowCount} rows uses JSON",
                        'details' => ['columns' => $jsonColumns, 'rows' => $rowCount],
                        'suggestion' => 'Consider normalizing to separate columns or using TEXT for better performance',
                    ];
                }
            }
            return null;
        };

        // Verifica colunas sem índice em FKs
        $this->checks[] = function (string $table) {
            $unindexedFk = $this->findUnindexedForeignKeys($table);
            if (!empty($unindexedFk)) {
                return [
                    'table' => $table,
                    'type' => 'unindexed_foreign_keys',
                    'severity' => 'warning',
                    'message' => "Foreign keys with no index in '{$table}'",
                    'details' => $unindexedFk,
                    'suggestion' => 'Add indexes on FK columns to improve JOINs and avoid deadlocks',
                ];
            }
            return null;
        };

        // Verifica colunas enum com valores dinâmicos (possível problema)
        $this->checks[] = function (string $table) {
            if ($this->driver !== 'mysql') return null;

            $enums = $this->getEnumColumns($table);
            foreach ($enums as $column => $values) {
                if (count($values) > 20) {
                    return [
                        'table' => $table,
                        'type' => 'large_enum',
                        'severity' => 'warning',
                        'message' => "ENUM column '{$column}' in '{$table}' has many values (" . count($values) . ")",
                        'suggestion' => 'Consider using a lookup table instead of ENUM for flexibility',
                    ];
                }
            }
            return null;
        };

        // Verifica tabelas sem updated_at
        $this->checks[] = function (string $table) {
            if (!$this->hasTimestampColumns($table)) {
                return [
                    'table' => $table,
                    'type' => 'missing_timestamps',
                    'severity' => 'info',
                    'message' => "Table '{$table}' with no timestamp columns",
                    'suggestion' => 'Adicione $table->timestamps() para rastrear created_at e updated_at',
                ];
            }
            return null;
        };

        // Verifica colunas nullable sem default (problema em inserts)
        $this->checks[] = function (string $table) {
            $nullableNoDefault = $this->getNullableWithoutDefault($table);
            if (!empty($nullableNoDefault)) {
                return [
                    'table' => $table,
                    'type' => 'nullable_without_default',
                    'severity' => 'info',
                    'message' => "Nullable columns with no default value in '{$table}'",
                    'details' => $nullableNoDefault,
                    'suggestion' => 'Add default values or make NOT NULL to avoid ambiguity',
                ];
            }
            return null;
        };

        // Verifica índices que não estão sendo usados (MySQL)
        $this->checks[] = function (string $table) {
            if ($this->driver !== 'mysql') return null;

            $unusedIndexes = $this->getUnusedIndexes($table);
            if (!empty($unusedIndexes)) {
                return [
                    'table' => $table,
                    'type' => 'unused_indexes',
                    'severity' => 'info',
                    'message' => "Unused indexes in '{$table}'",
                    'details' => $unusedIndexes,
                    'suggestion' => 'Consider removing unused indexes to save space',
                ];
            }
            return null;
        };

        // Verifica tabelas com auto_increment próximo do limite
        $this->checks[] = function (string $table) {
            if ($this->driver !== 'mysql') return null;

            $autoIncrementInfo = $this->getAutoIncrementInfo($table);
            if ($autoIncrementInfo && $autoIncrementInfo['percentage'] > 80) {
                return [
                    'table' => $table,
                    'type' => 'auto_increment_near_limit',
                    'severity' => 'critical',
                    'message' => "Auto increment of '{$table}' near the limit ({$autoIncrementInfo['percentage']}%)",
                    'details' => $autoIncrementInfo,
                    'suggestion' => 'Migrate to BIGINT UNSIGNED or consider archiving old data',
                ];
            }
            return null;
        };

        // Verifica colunas string muito grandes para índices
        $this->checks[] = function (string $table) {
            if ($this->driver !== 'mysql') return null;

            $largeStringIndexes = $this->getLargeStringIndexes($table);
            if (!empty($largeStringIndexes)) {
                return [
                    'table' => $table,
                    'type' => 'large_string_index',
                    'severity' => 'warning',
                    'message' => "Indexes on very large VARCHAR columns in '{$table}'",
                    'details' => $largeStringIndexes,
                    'suggestion' => 'Use index prefix: $table->index(DB::raw("email(191)")) or reduce the size',
                ];
            }
            return null;
        };
    }

    private function getAllTables(): array
    {
        $tables = [];

        if ($this->driver === 'mysql') {
            $results = DB::select('SHOW TABLES');
            $dbName = config("database.connections.{$this->driver}.database");
            $key = "Tables_in_{$dbName}";
            foreach ($results as $result) {
                $tables[] = $result->{$key} ?? $result->{array_key_first((array)$result)};
            }
        } elseif ($this->driver === 'pgsql') {
            $results = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            foreach ($results as $result) {
                $tables[] = $result->tablename;
            }
        } elseif ($this->driver === 'sqlite') {
            $results = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            foreach ($results as $result) {
                $tables[] = $result->name;
            }
        }

        return $tables;
    }

    /**
     * Retorna informações sobre as colunas da tabela (multi-driver).
     */
    private function getTableColumns(string $table): array
    {
        $columns = [];

        if ($this->driver === 'mysql') {
            $results = DB::select("SHOW COLUMNS FROM {$table}");
            foreach ($results as $result) {
                $columns[] = (object) [
                    'name' => $result->Field,
                    'type' => $result->Type,
                    'nullable' => $result->Null === 'YES',
                    'default' => $result->Default,
                    'extra' => $result->Extra,
                ];
            }
        } elseif ($this->driver === 'pgsql') {
            $results = DB::select("
                SELECT
                    column_name,
                    data_type,
                    is_nullable,
                    column_default,
                    character_maximum_length,
                    udt_name
                FROM information_schema.columns
                WHERE table_name = ?
                ORDER BY ordinal_position
            ", [$table]);

            foreach ($results as $result) {
                // Detecta se é identity/auto_increment
                $extra = '';
                if (str_contains($result->column_default ?? '', 'nextval')) {
                    $extra = 'identity';
                }

                $columns[] = (object) [
                    'name' => $result->column_name,
                    'type' => $result->udt_name === 'varchar' ? "varchar({$result->character_maximum_length})" : $result->data_type,
                    'nullable' => $result->is_nullable === 'YES',
                    'default' => $result->column_default,
                    'extra' => $extra,
                ];
            }
        } elseif ($this->driver === 'sqlite') {
            $results = DB::select("PRAGMA table_info({$table})");
            foreach ($results as $result) {
                $columns[] = (object) [
                    'name' => $result->name,
                    'type' => $result->type,
                    'nullable' => !$result->notnull,
                    'default' => $result->dflt_value,
                    'extra' => $result->pk ? 'auto_increment' : '',
                ];
            }
        }

        return $columns;
    }

    private function hasPrimaryKey(string $table): bool
    {
        if ($this->driver === 'mysql') {
            $result = DB::select("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'");
            return !empty($result);
        }

        if ($this->driver === 'pgsql') {
            $result = DB::select("
                SELECT constraint_name
                FROM information_schema.table_constraints
                WHERE table_name = ? AND constraint_type = 'PRIMARY KEY'
            ", [$table]);
            return !empty($result);
        }

        if ($this->driver === 'sqlite') {
            $result = DB::select("PRAGMA table_info({$table})");
            foreach ($result as $column) {
                if ($column->pk == 1) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    private function findDuplicateIndexes(string $table): array
    {
        $duplicates = [];
        $grouped = [];

        if ($this->driver === 'mysql') {
            $indexes = DB::select("SHOW INDEX FROM {$table}");

            foreach ($indexes as $index) {
                $keyName = $index->Key_name;
                if (!isset($grouped[$keyName])) {
                    $grouped[$keyName] = [];
                }
                $grouped[$keyName][] = $index->Column_name;
            }
        } elseif ($this->driver === 'pgsql') {
            $results = DB::select("
                SELECT
                    i.relname as index_name,
                    a.attname as column_name
                FROM pg_class t
                JOIN pg_index ix ON t.oid = ix.indrelid
                JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                WHERE t.relname = ?
                ORDER BY i.relname, array_position(ix.indkey, a.attnum)
            ", [$table]);

            foreach ($results as $result) {
                if (!isset($grouped[$result->index_name])) {
                    $grouped[$result->index_name] = [];
                }
                $grouped[$result->index_name][] = $result->column_name;
            }
        }

        // Compara índices para encontrar duplicados
        $seen = [];
        foreach ($grouped as $name => $columns) {
            sort($columns);
            $key = implode(',', $columns);
            if (isset($seen[$key])) {
                $duplicates[] = [
                    'indexes' => [$seen[$key], $name],
                    'columns' => $columns,
                ];
            } else {
                $seen[$key] = $name;
            }
        }

        return $duplicates;
    }

    private function getTableRowCount(string $table): int
    {
        if ($this->driver === 'mysql') {
            $result = DB::select("SHOW TABLE STATUS LIKE '{$table}'");
            return $result[0]->Rows ?? 0;
        }

        return DB::table($table)->count();
    }

    private function getJsonColumns(string $table): array
    {
        $jsonColumns = [];
        $columns = $this->getTableColumns($table);

        foreach ($columns as $column) {
            $type = strtolower($column->type);
            if (str_contains($type, 'json')) {
                $jsonColumns[] = $column->name;
            }
        }

        return $jsonColumns;
    }

    private function findUnindexedForeignKeys(string $table): array
    {
        $unindexed = [];
        $columns = $this->getTableColumns($table);
        $possibleFkColumns = [];

        foreach ($columns as $column) {
            if (str_ends_with($column->name, '_id')) {
                $possibleFkColumns[] = $column->name;
            }
        }

        if (empty($possibleFkColumns)) {
            return [];
        }

        // Verifica quais têm índice
        $indexedColumns = $this->getIndexedColumns($table);

        foreach ($possibleFkColumns as $column) {
            if (!in_array($column, $indexedColumns)) {
                $unindexed[] = $column;
            }
        }

        return $unindexed;
    }

    private function getIndexedColumns(string $table): array
    {
        $indexed = [];

        if ($this->driver === 'mysql') {
            $indexes = DB::select("SHOW INDEX FROM {$table}");
            foreach ($indexes as $index) {
                $indexed[] = $index->Column_name;
            }
        } elseif ($this->driver === 'pgsql') {
            $result = DB::select("
                SELECT a.attname as column_name
                FROM pg_class t
                JOIN pg_index ix ON t.oid = ix.indrelid
                JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                WHERE t.relname = ?
            ", [$table]);
            foreach ($result as $row) {
                $indexed[] = $row->column_name;
            }
        }

        return array_unique($indexed);
    }

    private function getEnumColumns(string $table): array
    {
        $enums = [];

        if ($this->driver === 'mysql') {
            $columns = $this->getTableColumns($table);
            foreach ($columns as $column) {
                if (str_starts_with(strtoupper($column->type), 'ENUM')) {
                    // Extrai valores do ENUM
                    preg_match_all("/'([^']+)'/", $column->type, $matches);
                    $enums[$column->name] = $matches[1] ?? [];
                }
            }
        } elseif ($this->driver === 'pgsql') {
            // PostgreSQL não tem ENUM nativo do mesmo jeito, mas pode ter via CREATE TYPE
            $result = DB::select("
                SELECT column_name, udt_name
                FROM information_schema.columns
                WHERE table_name = ?
                AND data_type = 'USER-DEFINED'
            ", [$table]);
            foreach ($result as $row) {
                // Tenta pegar valores do tipo ENUM
                $enumValues = DB::select("
                    SELECT enumlabel
                    FROM pg_enum
                    WHERE enumtypid = (
                        SELECT oid FROM pg_type WHERE typname = ?
                    )
                    ORDER BY enumsortorder
                ", [$row->udt_name]);
                $enums[$row->column_name] = array_map(fn($v) => $v->enumlabel, $enumValues);
            }
        }

        return $enums;
    }

    private function hasTimestampColumns(string $table): bool
    {
        $columns = $this->getTableColumns($table);
        $columnNames = array_map(fn($c) => $c->name, $columns);

        return in_array('created_at', $columnNames) && in_array('updated_at', $columnNames);
    }

    private function getNullableWithoutDefault(string $table): array
    {
        $columns = [];
        $tableColumns = $this->getTableColumns($table);

        foreach ($tableColumns as $column) {
            $isNullable = $column->nullable ?? false;
            $hasDefault = $column->default !== null;
            $isAutoIncrement = ($column->extra ?? '') === 'auto_increment' || ($column->extra ?? '') === 'identity';

            if ($isNullable && !$hasDefault && !$isAutoIncrement) {
                $columns[] = $column->name;
            }
        }

        return $columns;
    }

    private function getUnusedIndexes(string $table): array
    {
        $unused = [];

        if ($this->driver === 'mysql') {
            $result = DB::select("
                SELECT
                    INDEX_NAME,
                    CARDINALITY,
                    SEQ_IN_INDEX
                FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND CARDINALITY IS NOT NULL
                AND CARDINALITY = 0
                AND INDEX_NAME != 'PRIMARY'
            ", [$table]);

            foreach ($result as $index) {
                $unused[] = $index->INDEX_NAME;
            }
        } elseif ($this->driver === 'pgsql') {
            // PostgreSQL não tem estatísticas de uso de índice tão fácil
            // Esta é uma verificação aproximada usando pg_stat_user_indexes
            $result = DB::select("
                SELECT indexrelname
                FROM pg_stat_user_indexes
                WHERE relname = ?
                AND idx_scan = 0
                AND indexrelname NOT LIKE '%_pkey'
            ", [$table]);

            foreach ($result as $index) {
                $unused[] = $index->indexrelname;
            }
        }

        return array_unique($unused);
    }

    private function getAutoIncrementInfo(string $table): ?array
    {
        if ($this->driver === 'mysql') {
            $result = DB::select("
                SELECT
                    AUTO_INCREMENT,
                    (CASE DATA_TYPE
                        WHEN 'tinyint' THEN 255
                        WHEN 'smallint' THEN 65535
                        WHEN 'mediumint' THEN 16777215
                        WHEN 'int' THEN 4294967295
                        WHEN 'bigint' THEN 18446744073709551615
                    END) as max_value
                FROM information_schema.TABLES t
                JOIN information_schema.COLUMNS c ON t.TABLE_NAME = c.TABLE_NAME
                WHERE t.TABLE_SCHEMA = DATABASE()
                AND t.TABLE_NAME = ?
                AND c.EXTRA = 'auto_increment'
                LIMIT 1
            ", [$table]);

            if (!empty($result) && $result[0]->AUTO_INCREMENT) {
                $current = $result[0]->AUTO_INCREMENT;
                $max = $result[0]->max_value;
                $percentage = ($current / $max) * 100;

                return [
                    'current' => $current,
                    'max' => $max,
                    'percentage' => round($percentage, 2),
                ];
            }
        } elseif ($this->driver === 'pgsql') {
            // Para PostgreSQL, verifica sequences
            $result = DB::select("
                SELECT
                    s.relname as sequence_name,
                    seq.last_value,
                    seq.max_value
                FROM pg_class s
                JOIN pg_namespace n ON n.oid = s.relnamespace
                JOIN pg_depend d ON d.objid = s.oid
                JOIN pg_class t ON t.oid = d.refobjid
                LEFT JOIN pg_sequences seq ON seq.schemaname = n.nspname AND seq.sequencename = s.relname
                WHERE s.relkind = 'S'
                AND t.relname = ?
                AND n.nspname = 'public'
            ", [$table]);

            if (!empty($result) && $result[0]->last_value) {
                $current = (int) $result[0]->last_value;
                $max = (int) ($result[0]->max_value ?? PHP_INT_MAX);
                $percentage = $max > 0 ? ($current / $max) * 100 : 0;

                return [
                    'current' => $current,
                    'max' => $max,
                    'percentage' => round($percentage, 2),
                ];
            }
        }

        return null;
    }

    private function getLargeStringIndexes(string $table): array
    {
        $largeIndexes = [];

        if ($this->driver === 'mysql') {
            $indexes = DB::select("
                SELECT
                    INDEX_NAME,
                    COLUMN_NAME,
                    CHARACTER_MAXIMUM_LENGTH
                FROM information_schema.STATISTICS s
                JOIN information_schema.COLUMNS c ON s.TABLE_NAME = c.TABLE_NAME AND s.COLUMN_NAME = c.COLUMN_NAME
                WHERE s.TABLE_SCHEMA = DATABASE()
                AND s.TABLE_NAME = ?
                AND c.DATA_TYPE = 'varchar'
                AND c.CHARACTER_MAXIMUM_LENGTH > 191
            ", [$table]);

            foreach ($indexes as $index) {
                $largeIndexes[] = [
                    'index' => $index->INDEX_NAME,
                    'column' => $index->COLUMN_NAME,
                    'length' => $index->CHARACTER_MAXIMUM_LENGTH,
                ];
            }
        } elseif ($this->driver === 'pgsql') {
            // PostgreSQL não tem esse problema de limite de índice no mesmo sentido
            // Mas podemos verificar índices em colunas muito grandes
            $result = DB::select("
                SELECT
                    i.relname as index_name,
                    a.attname as column_name,
                    c.character_maximum_length
                FROM pg_class t
                JOIN pg_index ix ON t.oid = ix.indrelid
                JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
                JOIN information_schema.columns c ON c.table_name = t.relname AND c.column_name = a.attname
                WHERE t.relname = ?
                AND c.data_type = 'character varying'
                AND c.character_maximum_length > 255
            ", [$table]);

            foreach ($result as $index) {
                $largeIndexes[] = [
                    'index' => $index->index_name,
                    'column' => $index->column_name,
                    'length' => $index->character_maximum_length,
                ];
            }
        }

        return $largeIndexes;
    }
}
