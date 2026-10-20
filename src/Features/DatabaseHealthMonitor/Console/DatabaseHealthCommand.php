<?php

namespace RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\DatabaseHealthMonitor;

class DatabaseHealthCommand extends Command
{
    protected $signature = 'risetools:db-health
                            {--table= : Check only a specific table}
                            {--severity= : Filter by severity (critical, warning, info)}
                            {--json : Print the report as JSON}
                            {--export= : Save the report as JSON to a file}';

    protected $description = 'Checks database health';

    private array $icons = [
        'critical' => '❌',
        'warning' => '⚠️',
        'info' => 'ℹ️',
    ];

    public function handle(DatabaseHealthMonitor $monitor): int
    {
        $severity = $this->option('severity');

        if ($severity && !isset($this->icons[$severity])) {
            $this->error("Invalid severity '{$severity}'. Use: " . implode(', ', array_keys($this->icons)) . '.');
            return self::FAILURE;
        }

        // Com --json a saída precisa ser só o JSON (para ser lida por jq/CI).
        if (!$this->option('json')) {
            $this->info('Analyzing database health...');
            $this->newLine();
        }

        // Só a tabela pedida, em vez de varrer o banco inteiro e filtrar depois.
        $results = $this->option('table')
            ? $monitor->runForTable($this->option('table'))
            : $monitor->run();

        if ($severity) {
            $results = array_values(array_filter($results, fn($r) => $r['severity'] === $severity));
        }

        // Resumo, JSON e export refletem os mesmos filtros da listagem.
        $summary = $monitor->getSummary($results);

        if ($this->option('json')) {
            $this->line(json_encode($monitor->generateReport($results), JSON_PRETTY_PRINT));
            return $this->exitCode($summary);
        }

        if ($this->option('export')) {
            $path = $this->option('export');
            file_put_contents($path, json_encode($monitor->generateReport($results), JSON_PRETTY_PRINT));
            $this->info("Report saved to: {$path}");
            return $this->exitCode($summary);
        }

        $this->displaySummary($summary);
        $this->newLine();

        if (empty($results)) {
            $this->info('✅ No problems found! The database is healthy.');
            return self::SUCCESS;
        }

        $this->displayIssues($results);

        return $this->exitCode($summary);
    }

    /**
     * Falha (código != 0) quando há problema crítico ou aviso: útil em CI.
     */
    private function exitCode(array $summary): int
    {
        return $summary['healthy'] ? self::SUCCESS : self::FAILURE;
    }

    private function displaySummary(array $summary): void
    {
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Problems', $summary['total_issues']],
                ['Critical ❌', $summary['critical']],
                ['Warnings ⚠️', $summary['warning']],
                ['Info ℹ️', $summary['info']],
                ['Status', $summary['healthy'] ? '✅ Healthy' : '⚠️ Problems Detected'],
            ]
        );
    }

    private function displayIssues(array $issues): void
    {
        // Agrupa por severidade
        $grouped = [
            'critical' => [],
            'warning' => [],
            'info' => [],
        ];

        foreach ($issues as $issue) {
            $grouped[$issue['severity']][] = $issue;
        }

        // Exibe críticos primeiro
        foreach (['critical', 'warning', 'info'] as $severity) {
            if (empty($grouped[$severity])) {
                continue;
            }

            $icon = $this->icons[$severity];
            $this->warn("{$icon} " . ucfirst($severity) . ' problems:');
            $this->newLine();

            foreach ($grouped[$severity] as $issue) {
                $this->line("  <fg=red>{$issue['table']}</>");
                $this->line("  └─ {$issue['message']}");

                if (isset($issue['details'])) {
                    $this->line("  └─ Details: " . json_encode($issue['details']));
                }

                if (isset($issue['suggestion'])) {
                    $this->line("  └─ <fg=green>Suggestion:</> {$issue['suggestion']}");
                }

                $this->newLine();
            }
        }
    }
}
