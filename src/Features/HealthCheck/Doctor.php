<?php

namespace RiseTechApps\RiseTools\Features\HealthCheck;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks de saúde da aplicação, usados pelo `risetools:doctor` e pela rota
 * de readiness. Cada check devolve um detalhe (string) quando passa e lança
 * exceção quando falha.
 *
 * Checks próprios: `addCheck('nome', fn(array $options) => 'detalhe')` ou a
 * config `risetools.health.checks` (nome => classe invocável).
 */
class Doctor
{
    public const HEARTBEAT_KEY = 'risetools:scheduler:heartbeat';

    /**
     * @var array<int, string>
     */
    public const BUILT_IN = [
        'security', 'config', 'database', 'migrations', 'redis', 'cache', 'storage',
        'disk', 'queue', 'failed-jobs', 'smtp', 'scheduler', 'horizon',
    ];

    /**
     * @var array<string, callable>
     */
    protected array $custom = [];

    public function __construct(protected Application $app)
    {
    }

    /**
     * Registra um check próprio. O callable recebe as opções e devolve o
     * detalhe; para falhar, lança exceção.
     */
    public function addCheck(string $name, callable $check): self
    {
        $this->custom[Str::lower($name)] = $check;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function available(): array
    {
        return array_values(array_unique(array_merge(self::BUILT_IN, array_keys($this->customChecks()))));
    }

    /**
     * Checks que rodam sem --only: só os que fazem sentido para este app.
     *
     * @return array<int, string>
     */
    public function defaultChecks(): array
    {
        return array_values(array_filter($this->available(), function (string $check) {
            return match ($check) {
                // Falhava sempre em app sem Redis.
                'redis' => $this->usesRedis(),
                // Fora de produção normalmente não há scheduler/Horizon rodando.
                'scheduler' => config('risetools.health.scheduler.heartbeat', true) && $this->app->environment('production'),
                'horizon' => $this->horizonInstalled() && $this->app->environment('production'),
                default => true,
            };
        }));
    }

    /**
     * Converte "database, cache" na lista de checks válidos. Vazio: os padrão.
     *
     * @return array<int, string>
     */
    public function resolve(?string $only): array
    {
        if (blank($only)) {
            return $this->defaultChecks();
        }

        return collect(explode(',', $only))
            ->map(fn($check) => Str::lower(trim($check)))
            ->filter(fn($check) => in_array($check, $this->available(), true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Roda os checks.
     *
     * Opções: disk, mailer, min_disk_gb, max_failed_jobs, scheduler_max_age (minutos).
     *
     * @return array<int, array{check: string, ok: bool, detail: string, ms: float}>
     */
    public function run(array $checks, array $options = []): array
    {
        $results = [];

        foreach ($checks as $check) {
            $start = microtime(true);

            try {
                $detail = (string) $this->runCheck($check, $options);
                $ok = true;
            } catch (Throwable $e) {
                $detail = $e->getMessage();
                $ok = false;
            }

            $results[] = [
                'check' => $check,
                'ok' => $ok,
                'detail' => $detail,
                'ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        }

        return $results;
    }

    public static function healthy(array $results): bool
    {
        return collect($results)->every(fn($result) => $result['ok']);
    }

    protected function runCheck(string $check, array $options): string
    {
        $custom = $this->customChecks();

        if (isset($custom[$check])) {
            return $custom[$check]($options);
        }

        $method = 'check' . Str::studly($check);

        if (!in_array($check, self::BUILT_IN, true) || !method_exists($this, $method)) {
            throw new \InvalidArgumentException("Unknown check '{$check}'.");
        }

        return $this->{$method}($options);
    }

    /**
     * @return array<string, callable>
     */
    protected function customChecks(): array
    {
        $fromConfig = [];

        foreach ((array) config('risetools.health.checks', []) as $name => $class) {
            $fromConfig[Str::lower($name)] = fn(array $options) => $this->app->make($class)($options);
        }

        return array_merge($fromConfig, $this->custom);
    }

    protected function usesRedis(): bool
    {
        return in_array('redis', [
            config('cache.default'),
            config('queue.default'),
            config('session.driver'),
            config('broadcasting.default'),
        ], true)
            || config('cache.stores.' . config('cache.default') . '.driver') === 'redis'
            || config('queue.connections.' . config('queue.default') . '.driver') === 'redis';
    }

    protected function horizonInstalled(): bool
    {
        return interface_exists(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class);
    }

    /**
     * Store do heartbeat do scheduler, sem prefixo dinâmico: o scheduler e o
     * doctor precisam enxergar a mesma chave.
     */
    public static function heartbeatCache(): CacheRepository
    {
        $store = config('risetools.health.cache_store') ?: config('cache.default');

        try {
            return app('cache')->resolve($store);
        } catch (Throwable) {
            return Cache::store();
        }
    }

    protected function checkSecurity(array $options): string
    {
        $issues = [];

        if (blank(config('app.key'))) {
            $issues[] = 'APP_KEY not set';
        }

        if ($this->app->environment('production') && config('app.debug') === true) {
            $issues[] = 'APP_DEBUG=true in production';
        }

        if (!empty($issues)) {
            throw new \RuntimeException(implode('; ', $issues) . '.');
        }

        return 'env: ' . $this->app->environment() . ', debug: ' . (config('app.debug') ? 'on' : 'off');
    }

    protected function checkConfig(array $options): string
    {
        $cached = $this->app->configurationIsCached();

        // Config caching only matters in production; missing it there hurts perf.
        if ($this->app->environment('production') && !$cached) {
            throw new \RuntimeException('Config not cached in production (run config:cache).');
        }

        return $cached ? 'cached' : 'not cached (ok outside production)';
    }

    protected function checkDatabase(array $options): string
    {
        $connection = DB::connection();
        $connection->getPdo();
        $connection->select('select 1');

        return $connection->getName() . ' @ ' . $connection->getDatabaseName();
    }

    protected function checkMigrations(array $options): string
    {
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = $this->app->make('migrator');
        $repository = $migrator->getRepository();

        if (!$repository->repositoryExists()) {
            throw new \RuntimeException('Migrations table missing (run migrate).');
        }

        $ran = $repository->getRan();

        $paths = array_merge([$this->app->databasePath('migrations')], $migrator->paths());
        $files = $migrator->getMigrationFiles($paths);

        $pending = array_diff(array_keys($files), $ran);

        if (!empty($pending)) {
            throw new \RuntimeException(count($pending) . ' pending migration(s).');
        }

        return count($ran) . ' applied, 0 pending';
    }

    protected function checkDisk(array $options): string
    {
        $minGb = (float) ($options['min_disk_gb'] ?? 1);
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

    protected function checkRedis(array $options): string
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

    protected function checkCache(array $options): string
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

    protected function checkStorage(array $options): string
    {
        $disk = ($options['disk'] ?? null) ?: config('filesystems.default');
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

    protected function checkQueue(array $options): string
    {
        $connection = config('queue.default');

        // size() reaches the broker (redis/database/sqs) and counts pending jobs;
        // fails loud if the queue backend is unreachable.
        $size = $this->app->make('queue')->connection($connection)->size();

        return "connection: {$connection}, pending: {$size}";
    }

    protected function checkFailedJobs(array $options): string
    {
        if (!$this->app->bound('queue.failer')) {
            throw new \RuntimeException('Failed job provider not configured.');
        }

        $max = (int) ($options['max_failed_jobs'] ?? 0);
        $failer = $this->app->make('queue.failer');

        // count() conta no banco; all() carregava todos os jobs (payload
        // inclusive) na memória só para contar.
        $count = $failer instanceof \Illuminate\Queue\Failed\CountableFailedJobProvider
            ? $failer->count()
            : count($failer->all());

        if ($count > $max) {
            throw new \RuntimeException("{$count} failed job(s) (max {$max}).");
        }

        return "{$count} failed (max {$max})";
    }

    protected function checkSmtp(array $options): string
    {
        $mailer = ($options['mailer'] ?? null) ?: config('mail.default');

        $transport = Mail::mailer($mailer)->getSymfonyTransport();

        // Only SMTP transports expose a live socket connection to probe.
        if (method_exists($transport, 'start')) {
            $transport->start();

            if (method_exists($transport, 'stop')) {
                $transport->stop();
            }

            return "mailer: {$mailer}";
        }

        return "mailer: {$mailer} (non-smtp, skipped handshake)";
    }

    /**
     * O pacote agenda um heartbeat a cada minuto (risetools.health.scheduler.heartbeat):
     * se o último é mais velho que o limite, o `schedule:run` não está rodando.
     */
    protected function checkScheduler(array $options): string
    {
        if (!config('risetools.health.scheduler.heartbeat', true)) {
            throw new \RuntimeException('Heartbeat disabled (risetools.health.scheduler.heartbeat).');
        }

        $maxAge = (int) ($options['scheduler_max_age'] ?? config('risetools.health.scheduler.max_age_minutes', 5));
        $last = self::heartbeatCache()->get(self::HEARTBEAT_KEY);

        if (!$last) {
            throw new \RuntimeException('No scheduler heartbeat yet (is schedule:run in cron/schedule:work running?).');
        }

        $age = (int) floor((time() - (int) $last) / 60);

        if ($age > $maxAge) {
            throw new \RuntimeException("Last heartbeat {$age} min ago (max {$maxAge}).");
        }

        return "last heartbeat {$age} min ago";
    }

    protected function checkHorizon(array $options): string
    {
        if (!$this->horizonInstalled()) {
            throw new \RuntimeException('Laravel Horizon is not installed.');
        }

        $masters = $this->app->make(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();

        if (empty($masters)) {
            throw new \RuntimeException('Horizon is not running.');
        }

        $running = collect($masters)->filter(fn($master) => ($master->status ?? null) !== 'paused')->count();

        if ($running === 0) {
            throw new \RuntimeException('Horizon is paused.');
        }

        return "{$running} master supervisor(s) running";
    }
}
