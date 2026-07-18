<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;

class SnapshotCreateCommand extends Command
{
    protected $signature = 'risetools:snapshot-create
                            {name : Snapshot Name}
                            {--seed : Runs seeders after creating snapshot}';

    protected $description = 'Creates a snapshot of the current database';

    public function handle(DatabaseSnapshot $snapshot): int
    {
        $name = $this->argument('name');

        $this->info("Creating Snapshot '{$name}'...");

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
                $this->error("❌ Tool not found: {$tool}");
                $this->warn("📦 Install the database client:");
                $this->line("   Debian/Ubuntu: sudo apt-get install postgresql-client | mysql-client");
                $this->line("   Alpine: apk add postgresql-client | mysql-client");
                $this->newLine();
                $this->warn("🐛 Original error: {$message}");
            } else {
                $this->error("Error creating snapshot: {$message}");
            }

            return self::FAILURE;
        }
    }
}
