<?php

namespace RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseHealthMonitor\DatabaseHealthMonitor;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\BufferedOutput;

class HealthCheckCommand extends Command
{
    protected $signature = 'risetools:db:health
                            {--table= : Verificar apenas uma tabela específica}
                            {--severity= : Filtrar por severidade (critical, warning, info)}
                            {--json : Exportar resultado em JSON}
                            {--export= : Salvar relatório em arquivo}';

    protected $description = 'Verifica a saúde do banco de dados';

    private array $icons = [
        'critical' => '❌',
        'warning' => '⚠️',
        'info' => 'ℹ️',
    ];

    public function handle(DatabaseHealthMonitor $monitor): int
    {
        $this->info('Analisando saúde do banco de dados...');
        $this->newLine();

        $results = $monitor->run();
        $summary = $monitor->getSummary();

        // Filtra por severidade se especificado
        if ($this->option('severity')) {
            $severity = $this->option('severity');
            $results = array_filter($results, fn($r) => $r['severity'] === $severity);
        }

        // Filtra por tabela se especificado
        if ($this->option('table')) {
            $table = $this->option('table');
            $results = array_filter($results, fn($r) => $r['table'] === $table);
        }

        // Exporta JSON
        if ($this->option('json')) {
            $report = $monitor->generateReport();
            $this->line(json_encode($report, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        // Salva em arquivo
        if ($this->option('export')) {
            $path = $this->option('export');
            $report = $monitor->generateReport();
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT));
            $this->info("Relatório salvo em: {$path}");
            return self::SUCCESS;
        }

        // Exibe resumo
        $this->displaySummary($summary);
        $this->newLine();

        // Exibe problemas encontrados
        if (empty($results)) {
            $this->info('✅ Nenhum problema encontrado! O banco de dados está saudável.');
            return self::SUCCESS;
        }

        $this->displayIssues($results);

        // Retorna código de erro se houver problemas críticos
        if ($summary['critical'] > 0) {
            return self::FAILURE;
        }

        return $summary['healthy'] ? self::SUCCESS : self::FAILURE;
    }

    private function displaySummary(array $summary): void
    {
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Total de Problemas', $summary['total_issues']],
                ['Críticos ❌', $summary['critical']],
                ['Avisos ⚠️', $summary['warning']],
                ['Infos ℹ️', $summary['info']],
                ['Status', $summary['healthy'] ? '✅ Saudável' : '⚠️ Problemas Detectados'],
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
            $this->warn("{$icon} Problemas {$severity}:");
            $this->newLine();

            foreach ($grouped[$severity] as $issue) {
                $this->line("  <fg=red>{$issue['table']}</>");
                $this->line("  └─ {$issue['message']}");

                if (isset($issue['details'])) {
                    $this->line("  └─ Detalhes: " . json_encode($issue['details']));
                }

                if (isset($issue['suggestion'])) {
                    $this->line("  └─ <fg=green>Sugestão:</> {$issue['suggestion']}");
                }

                $this->newLine();
            }
        }
    }
}
