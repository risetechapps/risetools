<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\Exceptions\ToolNotFoundException;

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

            $this->info("Snapshot '{$name}' created successfully!");

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
            $this->error("Error creating snapshot: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
