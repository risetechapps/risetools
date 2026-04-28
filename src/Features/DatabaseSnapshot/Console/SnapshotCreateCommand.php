<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;

class SnapshotCreateCommand extends Command
{
    protected $signature = 'risetools:snapshot:create
                            {name : Nome do snapshot}
                            {--seed : Executa seeders após criar snapshot}';

    protected $description = 'Cria um snapshot do banco de dados atual';

    public function handle(DatabaseSnapshot $snapshot): int
    {
        $name = $this->argument('name');

        $this->info("Criando snapshot '{$name}'...");

        try {
            $callback = null;

            if ($this->option('seed')) {
                $callback = function () {
                    $this->call('db:seed'); // Comando nativo do Laravel
                };
            }

            $snapshot->create($name, $callback);

            $this->info("Snapshot '{$name}' criado com sucesso!");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $message = $e->getMessage();

            // Mensagem amigável para ferramentas não instaladas
            if (str_contains($message, 'pg_dump') || str_contains($message, 'mysqldump')) {
                $tool = str_contains($message, 'pg_dump') ? 'pg_dump (PostgreSQL client)' : 'mysqldump (MySQL client)';
                $this->error("❌ Ferramenta não encontrada: {$tool}");
                $this->warn("📦 Instale o cliente do banco de dados:");
                $this->line("   Debian/Ubuntu: sudo apt-get install postgresql-client | mysql-client");
                $this->line("   Alpine: apk add postgresql-client | mysql-client");
                $this->newLine();
                $this->warn("🐛 Erro original: {$message}");
            } else {
                $this->error("Erro ao criar snapshot: {$message}");
            }

            return self::FAILURE;
        }
    }
}
