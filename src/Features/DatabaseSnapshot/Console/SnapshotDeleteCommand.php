<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Console;

use Illuminate\Console\Command;
use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;

class SnapshotDeleteCommand extends Command
{
    protected $signature = 'risetools:snapshot:delete
                            {name : Nome do snapshot}';

    protected $description = 'Remove um snapshot';

    public function handle(DatabaseSnapshot $snapshot): int
    {
        $name = $this->argument('name');

        if (!$snapshot->exists($name)) {
            $this->error("Snapshot '{$name}' não encontrado.");
            return self::FAILURE;
        }

        if (!$this->confirm("Deseja remover o snapshot '{$name}'?")) {
            $this->info('Operação cancelada.');
            return self::SUCCESS;
        }

        if ($snapshot->delete($name)) {
            $this->info("Snapshot '{$name}' removido com sucesso.");
            return self::SUCCESS;
        }

        $this->error("Não foi possível remover o snapshot.");
        return self::FAILURE;
    }
}
