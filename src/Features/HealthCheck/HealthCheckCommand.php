<?php

namespace RiseTechApps\RiseTools\Features\HealthCheck;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class HealthCheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rise:doctor
        {--only= : Comma separated list of checks to run (database,redis,storage,smtp)}
        {--disk= : Storage disk to test (defaults to filesystems.default)}
        {--mailer= : Mailer to test (defaults to mail.default)}
        {--min-disk-gb=1 : Minimum free space in GB before the disk check fails}
        {--max-failed-jobs=0 : Max tolerated failed jobs before the check fails}';

    /**
     * @var string
     */
    protected $description = 'Diagnose connectivity: database, redis, storage and smtp.';

    /**
     * @var array<int, string>
     */
    protected array $available = ['security', 'config', 'database', 'migrations', 'redis', 'cache', 'storage', 'disk', 'queue', 'failed-jobs', 'smtp'];

    public function handle(): int
    {
        $checks = $this->resolveChecks();

        if (empty($checks)) {
            $this->components->error('No valid checks selected. Available: ' . implode(', ', $this->available));
            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Running RiseTools doctor...');

        $failed = 0;

        foreach ($checks as $check) {
            $method = 'check' . Str::studly($check);

            $start = microtime(true);

            try {
                $detail = $this->{$method}();
                $elapsed = $this->elapsed($start);
                $this->components->twoColumnDetail(
                    sprintf('%s <fg=gray>%s</>', ucfirst($check), $detail),
                    "<fg=green>OK</> <fg=gray>{$elapsed}</>"
                );
            } catch (Throwable $e) {
                $failed++;
                $elapsed = $this->elapsed($start);
                $this->components->twoColumnDetail(
                    sprintf('%s <fg=gray>%s</>', ucfirst($check), $e->getMessage()),
                    "<fg=red>FAIL</> <fg=gray>{$elapsed}</>"
                );
            }
        }

        $this->newLine();

        if ($failed > 0) {
            $this->components->error("{$failed} check(s) failed.");
            return self::FAILURE;
        }

        $this->components->info('All checks passed.');
        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveChecks(): array
    {
        $only = $this->option('only');

        if (blank($only)) {
            return $this->available;
        }

        return collect(explode(',', $only))
            ->map(fn($c) => Str::lower(trim($c)))
            ->filter(fn($c) => in_array($c, $this->available, true))
            ->unique()
            ->values()
            ->all();
    }

    protected function checkSecurity(): string
    {
        $issues = [];

        if (blank(config('app.key'))) {
            $issues[] = 'APP_KEY not set';
        }

        if ($this->laravel->environment('production') && config('app.debug') === true) {
            $issues[] = 'APP_DEBUG=true in production';
        }

        if (!empty($issues)) {
            throw new \RuntimeException(implode('; ', $issues) . '.');
        }

        return 'env: ' . $this->laravel->environment() . ', debug: ' . (config('app.debug') ? 'on' : 'off');
    }

    protected function checkConfig(): string
    {
        $cached = $this->laravel->configurationIsCached();

        // Config caching only matters in production; missing it there hurts perf.
        if ($this->laravel->environment('production') && !$cached) {
            throw new \RuntimeException('Config not cached in production (run config:cache).');
        }

        return $cached ? 'cached' : 'not cached (ok outside production)';
    }

    protected function checkDatabase(): string
    {
        $connection = DB::connection();
        $connection->getPdo();
        $connection->select('select 1');

        return $connection->getName() . ' @ ' . $connection->getDatabaseName();
    }

    protected function checkMigrations(): string
    {
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');
        $repository = $migrator->getRepository();

        if (!$repository->repositoryExists()) {
            throw new \RuntimeException('Migrations table missing (run migrate).');
        }

        $ran = $repository->getRan();

        $paths = array_merge([$this->laravel->databasePath('migrations')], $migrator->paths());
        $files = $migrator->getMigrationFiles($paths);

        $pending = array_diff(array_keys($files), $ran);

        if (!empty($pending)) {
            throw new \RuntimeException(count($pending) . ' pending migration(s).');
        }

        return count($ran) . ' applied, 0 pending';
    }

    protected function checkDisk(): string
    {
        $minGb = (float) $this->option('min-disk-gb');
        $path = storage_path();

        $free = @disk_free_space($path);

        if ($free === false) {
            throw new \RuntimeException("Unable to read free space for {$path}.");
        }

        $freeGb = round($free / 1073741824, 2);

        if ($freeGb < $minGb) {
            throw new \RuntimeException("Low disk: {$freeGb}GB free (min {$minGb}GB).");
        }

        return "{$freeGb}GB free (min {$minGb}GB)";
    }

    protected function checkRedis(): string
    {
        $connection = Redis::connection();
        $response = $connection->ping();

        // phpredis returns true; predis returns a Status object stringifying to "PONG".
        $normalized = is_bool($response)
            ? ($response ? 'PONG' : '')
            : Str::upper(trim((string) $response));

        if ($normalized !== 'PONG') {
            throw new \RuntimeException('Unexpected ping response.');
        }

        return 'ping (' . config('database.redis.client', 'phpredis') . ')';
    }

    protected function checkCache(): string
    {
        $store = config('cache.default');
        $key = 'rise-doctor:' . Str::uuid();
        $expected = (string) now()->timestamp;

        try {
            Cache::store($store)->put($key, $expected, 10);

            if (Cache::store($store)->get($key) !== $expected) {
                throw new \RuntimeException('Cache read mismatch (write not persisted).');
            }
        } finally {
            Cache::store($store)->forget($key);
        }

        return "store: {$store}";
    }

    protected function checkStorage(): string
    {
        $disk = $this->option('disk') ?: config('filesystems.default');
        $filesystem = Storage::disk($disk);

        $path = 'rise-doctor/' . Str::uuid() . '.tmp';
        $payload = 'rise-doctor:' . now()->timestamp;

        try {
            $filesystem->put($path, $payload);

            if ($filesystem->get($path) !== $payload) {
                throw new \RuntimeException('Read payload mismatch.');
            }
        } finally {
            if ($filesystem->exists($path)) {
                $filesystem->delete($path);
            }
        }

        return "disk: {$disk}";
    }

    protected function checkQueue(): string
    {
        $connection = config('queue.default');

        // size() reaches the broker (redis/database/sqs) and counts pending jobs;
        // fails loud if the queue backend is unreachable.
        $size = app('queue')->connection($connection)->size();

        return "connection: {$connection}, pending: {$size}";
    }

    protected function checkFailedJobs(): string
    {
        if (!$this->laravel->bound('queue.failer')) {
            throw new \RuntimeException('Failed job provider not configured.');
        }

        $max = (int) $this->option('max-failed-jobs');
        $count = count($this->laravel->make('queue.failer')->all());

        if ($count > $max) {
            throw new \RuntimeException("{$count} failed job(s) (max {$max}).");
        }

        return "{$count} failed (max {$max})";
    }

    protected function checkSmtp(): string
    {
        $mailer = $this->option('mailer') ?: config('mail.default');

        $transport = Mail::mailer($mailer)->getSymfonyTransport();

        // Only SMTP transports expose a live socket connection to probe.
        if (method_exists($transport, 'start')) {
            $transport->start();

            if (method_exists($transport, 'stop')) {
                $transport->stop();
            }

            return "mailer: {$mailer}";
        }

        return "mailer: {$mailer} <fg=yellow>(non-smtp, skipped handshake)</>";
    }

    protected function elapsed(float $start): string
    {
        return number_format((microtime(true) - $start) * 1000, 1) . 'ms';
    }
}
