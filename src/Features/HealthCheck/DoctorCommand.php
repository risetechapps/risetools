<?php

namespace RiseTechApps\RiseTools\Features\HealthCheck;

use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'risetools:doctor
        {--only= : Comma separated list of checks to run (security,config,database,migrations,redis,cache,storage,disk,queue,failed-jobs,smtp,scheduler,horizon or a custom check)}
        {--disk= : Storage disk to test (defaults to filesystems.default)}
        {--mailer= : Mailer to test (defaults to mail.default)}
        {--min-disk-gb=1 : Minimum free space in GB before the disk check fails}
        {--max-failed-jobs=0 : Max tolerated failed jobs before the check fails}
        {--scheduler-max-age= : Max minutes since the last scheduler heartbeat (defaults to risetools.health.scheduler.max_age_minutes)}
        {--json : Print the result as JSON}';

    /**
     * @var string
     */
    protected $description = 'Diagnose the application: security, config, database, migrations, redis, cache, storage, disk, queue, failed jobs, smtp, scheduler and horizon.';

    public function handle(Doctor $doctor): int
    {
        $checks = $doctor->resolve($this->option('only'));

        if (empty($checks)) {
            $message = 'No valid checks selected. Available: ' . implode(', ', $doctor->available());

            if ($this->option('json')) {
                $this->line(json_encode(['healthy' => false, 'error' => $message, 'checks' => []], JSON_PRETTY_PRINT));
            } else {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        if (!$this->option('json')) {
            $this->newLine();
            $this->components->info('Running RiseTools doctor...');
        }

        $results = $doctor->run($checks, array_filter([
            'disk' => $this->option('disk'),
            'mailer' => $this->option('mailer'),
            'min_disk_gb' => $this->option('min-disk-gb'),
            'max_failed_jobs' => $this->option('max-failed-jobs'),
            'scheduler_max_age' => $this->option('scheduler-max-age'),
        ], fn($value) => $value !== null && $value !== ''));

        $healthy = Doctor::healthy($results);

        // Com --json a saída é só o JSON (para monitoramento/CI).
        if ($this->option('json')) {
            $this->line(json_encode(['healthy' => $healthy, 'checks' => $results], JSON_PRETTY_PRINT));

            return $healthy ? self::SUCCESS : self::FAILURE;
        }

        foreach ($results as $result) {
            $status = $result['ok'] ? '<fg=green>OK</>' : '<fg=red>FAIL</>';

            $this->components->twoColumnDetail(
                sprintf('%s <fg=gray>%s</>', ucfirst($result['check']), $result['detail']),
                "{$status} <fg=gray>{$result['ms']}ms</>"
            );
        }

        $this->newLine();

        if (!$healthy) {
            $failed = collect($results)->where('ok', false)->count();
            $this->components->error("{$failed} check(s) failed.");

            return self::FAILURE;
        }

        $this->components->info('All checks passed.');

        return self::SUCCESS;
    }
}
