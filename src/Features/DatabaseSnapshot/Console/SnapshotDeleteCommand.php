<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;

class SnapshotDeleteCommand extends Command
{
    protected $signature = 'risetools:snapshot-delete
                            {name : Snapshot Name}';

    protected $description = 'Removes a snapshot';

    public function handle(DatabaseSnapshot $snapshot): int
    {
        $name = $this->argument('name');

        if (!$snapshot->exists($name)) {
            $this->error("Snapshot '{$name}' not found.");
            return self::FAILURE;
        }

        if (!$this->confirm("Want to remove the snapshot '{$name}'?")) {
            $this->info('Operation canceled.');
            return self::SUCCESS;
        }

        if ($snapshot->delete($name)) {
            $this->info("Snapshot '{$name}' Successfully Removed.");
            return self::SUCCESS;
        }

        $this->error("The snapshot could not be removed.");
        return self::FAILURE;
    }
}
