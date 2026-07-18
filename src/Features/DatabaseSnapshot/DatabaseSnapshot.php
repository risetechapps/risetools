<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class DatabaseSnapshot
{
    private string $disk;
    private string $basePath;
    private array $config;

    public function __construct()
    {
        $this->disk = config('risetools.snapshot.disk', 'local');
        $this->basePath = config('risetools.snapshot.path', 'snapshots');
        $this->config = config('database.connections.' . config('database.default'));
    }

    /**
     * Cria um snapshot do banco de dados atual.
     */
    public function create(string $name, ?callable $callback = null): bool
    {
        $driver = $this->config['driver'] ?? 'mysql';
        $snapshotPath = $this->getSnapshotPath($name);

        // Garante que o diretório existe
        $this->ensureDirectoryExists();

        // Executa callback antes de criar (ex: seed banco)
        if ($callback) {
            $callback();
        }

        return match ($driver) {
            'mysql' => $this->createMysqlSnapshot($snapshotPath),
            'pgsql' => $this->createPostgresSnapshot($snapshotPath),
            'sqlite' => $this->createSqliteSnapshot($snapshotPath),
            default => throw new \Exception("Driver '{$driver}' não suportado para snapshots"),
        };
    }

    /**
     * Restaura um snapshot existente.
     */
    public function restore(string $name): bool
    {
        $snapshotPath = $this->getSnapshotPath($name);

        if (!$this->exists($name)) {
            throw new \Exception("Snapshot '{$name}' não encontrado em: {$snapshotPath}");
        }

        $driver = $this->config['driver'] ?? 'mysql';

        return match ($driver) {
            'mysql' => $this->restoreMysqlSnapshot($snapshotPath),
            'pgsql' => $this->restorePostgresSnapshot($snapshotPath),
            'sqlite' => $this->restoreSqliteSnapshot($snapshotPath),
            default => throw new \Exception("Driver '{$driver}' não suportado para snapshots"),
        };
    }

    /**
     * Verifica se um snapshot existe.
     */
    public function exists(string $name): bool
    {
        return file_exists($this->getSnapshotPath($name));
    }

    /**
     * Lista todos os snapshots disponíveis.
     */
    public function list(): array
    {
        $this->ensureDirectoryExists();
        $path = Storage::disk($this->disk)->path($this->basePath);

        if (!is_dir($path)) {
            return [];
        }

        $snapshots = [];
        $files = glob($path . '/*.{sql,sqlite,custom}', GLOB_BRACE);

        foreach ($files as $file) {
            $snapshots[] = [
                'name' => basename($file),
                'size' => $this->formatBytes(filesize($file)),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                'driver' => $this->config['driver'],
            ];
        }

        return $snapshots;
    }

    /**
     * Remove um snapshot.
     */
    public function delete(string $name): bool
    {
        $path = $this->getSnapshotPath($name);

        if (file_exists($path)) {
            return unlink($path);
        }

        return false;
    }

    /**
     * Limpa todos os snapshots.
     */
    public function purge(): int
    {
        $count = 0;
        $snapshots = $this->list();

        foreach ($snapshots as $snapshot) {
            if ($this->delete($snapshot['name'])) {
                $count++;
            }
        }

        return $count;
    }

    // MySQL Implementation
    private function createMysqlSnapshot(string $path): bool
    {
        $command = [
            'mysqldump',
            '--host=' . ($this->config['host'] ?? 'localhost'),
            '--port=' . ($this->config['port'] ?? '3306'),
            '--user=' . $this->config['username'],
            '--password=' . $this->config['password'],
            '--single-transaction',
            '--quick',
            '--lock-tables=false',
            $this->config['database'],
        ];

        $process = new Process($command);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('mysqldump failed: ' . $process->getErrorOutput());
        }

        file_put_contents($path, $process->getOutput());

        return true;
    }

    private function restoreMysqlSnapshot(string $path): bool
    {
        // Trunca todas as tabelas primeiro
        $this->truncateAllTables();

        $command = [
            'mysql',
            '--host=' . ($this->config['host'] ?? 'localhost'),
            '--port=' . ($this->config['port'] ?? '3306'),
            '--user=' . $this->config['username'],
            '--password=' . $this->config['password'],
            $this->config['database'],
        ];

        $process = new Process($command);
        $process->setInput(file_get_contents($path));
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('mysql restore failed: ' . $process->getErrorOutput());
        }

        return true;
    }

    // PostgreSQL Implementation
    private function createPostgresSnapshot(string $path): bool
    {
        $command = [
            'pg_dump',
            '--host=' . ($this->config['host'] ?? 'localhost'),
            '--port=' . ($this->config['port'] ?? '5432'),
            '--username=' . $this->config['username'],
            '--dbname=' . $this->config['database'],
            '--format=plain',
            '--no-owner',
            '--no-acl',
        ];

        $env = ['PGPASSWORD' => $this->config['password']];

        $process = new Process($command, null, $env);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('pg_dump failed: ' . $process->getErrorOutput());
        }

        file_put_contents($path, $process->getOutput());

        return true;
    }

    private function restorePostgresSnapshot(string $path): bool
    {
        $this->truncateAllTables();

        $command = [
            'psql',
            '--host=' . ($this->config['host'] ?? 'localhost'),
            '--port=' . ($this->config['port'] ?? '5432'),
            '--username=' . $this->config['username'],
            '--dbname=' . $this->config['database'],
            '--file=' . $path,
        ];

        $env = ['PGPASSWORD' => $this->config['password']];

        $process = new Process($command, null, $env);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('psql restore failed: ' . $process->getErrorOutput());
        }

        return true;
    }

    // SQLite Implementation (mais simples - só copia o arquivo)
    private function createSqliteSnapshot(string $path): bool
    {
        $databasePath = $this->config['database'];

        if (!file_exists($databasePath)) {
            throw new \Exception("SQLite database not found: {$databasePath}");
        }

        // Usiza SQLite online backup API se disponível, senão cópia simples
        $this->sqliteBackup($databasePath, $path);

        return true;
    }

    private function restoreSqliteSnapshot(string $path): bool
    {
        $databasePath = $this->config['database'];

        // Fecha conexões ativas
        DB::disconnect();

        // Copia o arquivo
        copy($path, $databasePath);

        return true;
    }

    private function sqliteBackup(string $source, string $destination): void
    {
        $pdo = DB::connection()->getPdo();

        // Ativa modo WAL para backup consistente
        $pdo->exec('PRAGMA journal_mode=WAL');

        // Faz backup usando a API do SQLite
        $backup = new \SQLite3($destination);
        $sourceDb = new \SQLite3($source);
        $sourceDb->backup($backup);
        $backup->close();
        $sourceDb->close();
    }

    private function truncateAllTables(): void
    {
        $driver = $this->config['driver'] ?? 'mysql';
        $tables = $this->getAllTables();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($tables as $table) {
            if ($driver === 'mysql') {
                DB::statement("TRUNCATE TABLE `{$table}`");
            } elseif ($driver === 'pgsql') {
                DB::statement("TRUNCATE TABLE \"{$table}\" RESTART IDENTITY CASCADE");
            } elseif ($driver === 'sqlite') {
                DB::statement("DELETE FROM `{$table}`");
                DB::statement("DELETE FROM sqlite_sequence WHERE name = '{$table}'");
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function getAllTables(): array
    {
        $driver = $this->config['driver'] ?? 'mysql';

        return match ($driver) {
            'mysql' => DB::select('SHOW TABLES'),
            'pgsql' => DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"),
            'sqlite' => DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"),
            default => [],
        };
    }

    private function getSnapshotPath(string $name): string
    {
        $extension = match ($this->config['driver'] ?? 'mysql') {
            'sqlite' => 'sqlite',
            default => 'sql',
        };

        $filename = str_replace(['/', '\\'], '_', $name) . '.' . $extension;

        return Storage::disk($this->disk)->path($this->basePath . '/' . $filename);
    }

    private function ensureDirectoryExists(): void
    {
        $path = Storage::disk($this->disk)->path($this->basePath);

        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
