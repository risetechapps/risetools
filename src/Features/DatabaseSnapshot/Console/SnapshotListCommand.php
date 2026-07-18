<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;

class SnapshotListCommand extends Command
{
    protected $signature = 'risetools:snapshot-list';

    protected $description = 'Lists all available snapshots';

    public function handle(DatabaseSnapshot $snapshot): int
    {
        $snapshots = $snapshot->list();

        if (empty($snapshots)) {
            $this->warn('No snapshots found.');
            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Size', 'Created on', 'Driver'],
            $snapshots
        );

        return self::SUCCESS;
    }
}
