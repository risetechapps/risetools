<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;

class SnapshotListCommand extends Command
{
    protected $signature = 'risetools:snapshot:list';

    protected $description = 'Lista todos os snapshots disponíveis';

    public function handle(DatabaseSnapshot $snapshot): int
    {
        $snapshots = $snapshot->list();

        if (empty($snapshots)) {
            $this->warn('Nenhum snapshot encontrado.');
            return self::SUCCESS;
        }

        $this->table(
            ['Nome', 'Tamanho', 'Criado em', 'Driver'],
            $snapshots
        );

        return self::SUCCESS;
    }
}
