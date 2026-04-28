<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;

class SnapshotRestoreCommand extends Command
{
    protected $signature = 'risetools:snapshot:restore
                            {name : Nome do snapshot}';

    protected $description = 'Restaura um snapshot do banco de dados';

    public function handle(DatabaseSnapshot $snapshot): int
    {
        $name = $this->argument('name');

        if (!$snapshot->exists($name)) {
            $this->error("Snapshot '{$name}' não encontrado.");
            return self::FAILURE;
        }

        $this->warn("Isso irá sobrescrever o banco de dados atual!");

        if (!$this->confirm('Deseja continuar?')) {
            $this->info('Operação cancelada.');
            return self::SUCCESS;
        }

        $this->info("Restaurando snapshot '{$name}'...");

        try {
            $snapshot->restore($name);
            $this->info("Snapshot '{$name}' restaurado com sucesso!");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'psql') || str_contains($message, 'mysql')) {
                $tool = str_contains($message, 'psql') ? 'psql (PostgreSQL client)' : 'mysql (MySQL client)';
                $this->error("❌ Ferramenta não encontrada: {$tool}");
                $this->warn("📦 Instale o cliente do banco de dados:");
                $this->line("   Debian/Ubuntu: sudo apt-get install postgresql-client | mysql-client");
                $this->line("   Alpine: apk add postgresql-client | mysql-client");
                $this->newLine();
                $this->warn("🐛 Erro original: {$message}");
            } else {
                $this->error("Erro ao restaurar snapshot: {$message}");
            }

            return self::FAILURE;
        }
    }
}
