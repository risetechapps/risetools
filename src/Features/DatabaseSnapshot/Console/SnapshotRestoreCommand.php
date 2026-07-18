<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;

class SnapshotRestoreCommand extends Command
{
    protected $signature = 'risetools:snapshot-restore
                            {name : Snapshot Name}';

    protected $description = 'Restores a snapshot of the database';

    public function handle(DatabaseSnapshot $snapshot): int
    {
        $name = $this->argument('name');

        if (!$snapshot->exists($name)) {
            $this->error("Snapshot '{$name}' not found.");
            return self::FAILURE;
        }

        $this->warn("This will overwrite the current database!");

        if (!$this->confirm('Deseja continuar?')) {
            $this->info('Operação cancelada.');
            return self::SUCCESS;
        }

        $this->info("Restaurando snapshot '{$name}'...");

        try {
            $snapshot->restore($name);
            $this->info("Snapshot '{$name}' Successfully Restored!");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $message = $e->getMessage();

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
