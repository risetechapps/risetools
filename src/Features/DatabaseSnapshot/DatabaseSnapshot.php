<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Exceptions\ToolNotFoundException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class DatabaseSnapshot
{
    private string $disk;
    private string $basePath;
    private array $config;

    public function __construct()
    {
        $this->disk = config('risetools.snapshot.disk', 'local');
        $this->basePath = trim(config('risetools.snapshot.path', 'snapshots'), '/');
        $this->config = config('database.connections.' . config('database.default'));
    }

    /**
     * Cria um snapshot do banco de dados atual.
     *
     * O dump vai para um arquivo temporário local (sem passar pela memória do
     * PHP) e depois é enviado por stream para o disco configurado, que pode
     * ser remoto (S3 etc.).
     */
    public function create(string $name, ?callable $callback = null): bool
    {
        $driver = $this->config['driver'] ?? 'mysql';

        // Executa callback antes de criar (ex: seed banco)
        if ($callback) {
            $callback();
        }

        $temp = $this->temporaryPath();

        try {
            match ($driver) {
                'mysql' => $this->createMysqlSnapshot($temp),
                'pgsql' => $this->createPostgresSnapshot($temp),
                'sqlite' => $this->createSqliteSnapshot($temp),
                default => throw new \Exception("Driver '{$driver}' is not supported for snapshots."),
            };

            $stream = fopen($temp, 'r');

            try {
                if (!$this->filesystem()->writeStream($this->getSnapshotFile($name), $stream)) {
                    throw new \Exception("Could not write snapshot '{$name}' to disk '{$this->disk}'.");
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return true;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Restaura um snapshot existente.
     */
    public function restore(string $name): bool
    {
        if (!$this->exists($name)) {
            throw new \Exception("Snapshot '{$name}' not found at: {$this->getSnapshotFile($name)} (disk: {$this->disk})");
        }

        $driver = $this->config['driver'] ?? 'mysql';

        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new \Exception("Driver '{$driver}' is not supported for snapshots.");
        }

        // Baixa por stream para um arquivo local: as ferramentas de restore
        // leem do disco, e o disco configurado pode ser remoto.
        $temp = $this->temporaryPath();

        try {
            $this->downloadTo($this->getSnapshotFile($name), $temp);

            return match ($driver) {
                'mysql' => $this->restoreMysqlSnapshot($temp),
                'pgsql' => $this->restorePostgresSnapshot($temp),
                'sqlite' => $this->restoreSqliteSnapshot($temp),
            };
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Verifica se um snapshot existe.
     */
    public function exists(string $name): bool
    {
        return $this->filesystem()->exists($this->getSnapshotFile($name));
    }

    /**
     * Lista todos os snapshots disponíveis.
     */
    public function list(): array
    {
        $filesystem = $this->filesystem();
        $snapshots = [];

        foreach ($filesystem->files($this->basePath) as $file) {
            if (!in_array(pathinfo($file, PATHINFO_EXTENSION), ['sql', 'sqlite'], true)) {
                continue;
            }

            $snapshots[] = [
                // Sem extensão: é o nome que restore/delete esperam.
                'name' => pathinfo($file, PATHINFO_FILENAME),
                'size' => $this->formatBytes($filesystem->size($file)),
                'created_at' => date('Y-m-d H:i:s', $filesystem->lastModified($file)),
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
        if (!$this->exists($name)) {
            return false;
        }

        return $this->filesystem()->delete($this->getSnapshotFile($name));
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
        $this->ensureTool('mysqldump');

        $command = [
            'mysqldump',
            '--host=' . ($this->config['host'] ?? 'localhost'),
            '--port=' . ($this->config['port'] ?? '3306'),
            '--user=' . $this->config['username'],
            '--single-transaction',
            '--quick',
            '--lock-tables=false',
            // Escreve direto no arquivo, sem passar o dump pela memória do PHP.
            '--result-file=' . $path,
            $this->config['database'],
        ];

        $process = new Process($command, null, $this->mysqlEnv());
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('mysqldump failed: ' . $process->getErrorOutput());
        }

        return true;
    }

    private function restoreMysqlSnapshot(string $path): bool
    {
        // Antes do truncate: sem o cliente instalado, o banco não pode ser limpo.
        $this->ensureTool('mysql');

        // Trunca todas as tabelas primeiro
        $this->truncateAllTables();

        $command = [
            'mysql',
            '--host=' . ($this->config['host'] ?? 'localhost'),
            '--port=' . ($this->config['port'] ?? '3306'),
            '--user=' . $this->config['username'],
            $this->config['database'],
        ];

        $input = fopen($path, 'r');

        try {
            $process = new Process($command, null, $this->mysqlEnv());
            // Stream como entrada: o arquivo não é carregado inteiro na memória.
            $process->setInput($input);
            $process->setTimeout(300);
            $process->run();
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }

        if (!$process->isSuccessful()) {
            throw new \Exception('mysql restore failed: ' . $process->getErrorOutput());
        }

        return true;
    }

    /**
     * Falha cedo, com exceção própria, quando o binário não está no PATH. Assim
     * "ferramenta ausente" não se confunde com erro da ferramenta (senha
     * errada, banco inexistente...), que sai como exceção comum com o stderr.
     */
    private function ensureTool(string $binary): void
    {
        if ((new ExecutableFinder())->find($binary) === null) {
            throw new ToolNotFoundException($binary);
        }
    }

    /**
     * Senha pelo ambiente do processo, não pelo argv: --password= aparece no
     * `ps` para qualquer usuário da máquina.
     */
    private function mysqlEnv(): array
    {
        $password = (string) ($this->config['password'] ?? '');

        return $password === '' ? [] : ['MYSQL_PWD' => $password];
    }

    // PostgreSQL Implementation
    private function createPostgresSnapshot(string $path): bool
    {
        $this->ensureTool('pg_dump');

        $command = [
            'pg_dump',
            '--host=' . ($this->config['host'] ?? 'localhost'),
            '--port=' . ($this->config['port'] ?? '5432'),
            '--username=' . $this->config['username'],
            '--dbname=' . $this->config['database'],
            '--format=plain',
            '--no-owner',
            '--no-acl',
            // DROP ... IF EXISTS antes de cada CREATE: o restore recria os
            // objetos em vez de esbarrar em "already exists".
            '--clean',
            '--if-exists',
            // Escreve direto no arquivo, sem passar o dump pela memória do PHP.
            '--file=' . $path,
        ];

        $env = ['PGPASSWORD' => $this->config['password']];

        $process = new Process($command, null, $env);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception('pg_dump failed: ' . $process->getErrorOutput());
        }

        return true;
    }

    private function restorePostgresSnapshot(string $path): bool
    {
        $this->ensureTool('psql');

        $command = [
            'psql',
            '--host=' . ($this->config['host'] ?? 'localhost'),
            '--port=' . ($this->config['port'] ?? '5432'),
            '--username=' . $this->config['username'],
            '--dbname=' . $this->config['database'],
            '--no-psqlrc',
            '--file=' . $path,
        ];

        if ($this->isCleanPostgresDump($path)) {
            // Dump com --clean: derruba e recria tudo numa transação só. Qualquer
            // erro aborta e faz rollback, o banco atual fica intacto.
            $command[] = '--single-transaction';
            $command[] = '--set=ON_ERROR_STOP=1';
        } else {
            // Snapshot antigo (sem --clean): os CREATE falham com "already exists"
            // e são ignorados, então limpa os dados antes para o COPY entrar.
            $this->truncateAllTables();
        }

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

        // Usa a SQLite online backup API
        $this->sqliteBackup($databasePath, $path);

        return true;
    }

    private function restoreSqliteSnapshot(string $path): bool
    {
        $databasePath = $this->config['database'];

        // Fecha conexões ativas
        DB::disconnect();

        if (!copy($path, $databasePath)) {
            throw new \Exception("Could not copy the snapshot to the SQLite database: {$databasePath}");
        }

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

    /**
     * Dump gerado com --clean traz os DROP antes do primeiro CREATE.
     */
    private function isCleanPostgresDump(string $path): bool
    {
        $handle = fopen($path, 'r');

        if (!$handle) {
            return false;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (str_starts_with($line, 'DROP ')) {
                    return true;
                }

                if (str_starts_with($line, 'CREATE ') || str_starts_with($line, 'COPY ')) {
                    return false;
                }
            }

            return false;
        } finally {
            fclose($handle);
        }
    }

    private function truncateAllTables(): void
    {
        $driver = $this->config['driver'] ?? 'mysql';
        $tables = $this->getAllTables();

        if (empty($tables)) {
            return;
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                foreach ($tables as $table) {
                    DB::statement('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        } elseif ($driver === 'pgsql') {
            // Um TRUNCATE só com todas as tabelas: o Postgres resolve as FKs entre elas.
            $list = implode(', ', array_map(
                fn($table) => '"' . str_replace('"', '""', $table) . '"',
                $tables
            ));

            DB::statement("TRUNCATE TABLE {$list} RESTART IDENTITY CASCADE");
        }
    }

    /**
     * @return array<int, string>
     */
    private function getAllTables(): array
    {
        $driver = $this->config['driver'] ?? 'mysql';

        $rows = match ($driver) {
            'mysql' => DB::select('SHOW TABLES'),
            'pgsql' => DB::select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'),
            'sqlite' => DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"),
            default => [],
        };

        // Cada linha é um stdClass de uma coluna (o nome varia por driver).
        return array_map(fn($row) => (string) array_values((array) $row)[0], $rows);
    }

    private function filesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    /**
     * Caminho do snapshot relativo ao disco (não um caminho local).
     */
    private function getSnapshotFile(string $name): string
    {
        $extension = match ($this->config['driver'] ?? 'mysql') {
            'sqlite' => 'sqlite',
            default => 'sql',
        };

        // Aceita o nome com a extensão (como aparecia no snapshot-list antigo).
        if (str_ends_with($name, '.' . $extension)) {
            $name = substr($name, 0, -strlen('.' . $extension));
        }

        $filename = str_replace(['/', '\\'], '_', $name) . '.' . $extension;

        return ltrim($this->basePath . '/' . $filename, '/');
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'risetools-snapshot-');

        if ($path === false) {
            throw new \Exception('Could not create a temporary file for the snapshot.');
        }

        return $path;
    }

    private function downloadTo(string $file, string $localPath): void
    {
        $source = $this->filesystem()->readStream($file);

        if (!is_resource($source)) {
            throw new \Exception("Could not read snapshot file '{$file}' from disk '{$this->disk}'.");
        }

        $target = fopen($localPath, 'w');

        try {
            stream_copy_to_stream($source, $target);
        } finally {
            fclose($target);

            if (is_resource($source)) {
                fclose($source);
            }
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
