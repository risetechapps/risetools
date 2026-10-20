<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Exceptions\ToolNotFoundException;

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

        if (!$this->confirm('Do you want to continue?')) {
            $this->info('Operation canceled.');
            return self::SUCCESS;
        }

        $this->info("Restoring snapshot '{$name}'...");

        try {
            $snapshot->restore($name);
            $this->info("Snapshot '{$name}' restored successfully!");
            return self::SUCCESS;
        } catch (ToolNotFoundException $e) {
            // Só quando o binário realmente não existe (checado antes de rodar).
            $this->error("❌ Tool not found: {$e->tool} ({$e->client()})");
            $this->warn("📦 Install the database client:");

            foreach ($e->installHints() as $system => $command) {
                $this->line("   {$system}: {$command}");
            }

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error("Error restoring snapshot: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
