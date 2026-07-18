<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Traits;

use RiseTechApps\RiseTools\Features\DatabaseSnapshot\DatabaseSnapshot;

trait InteractsWithSnapshots
{
    /**
     * Restaura um snapshot antes de executar os testes.
     */
    protected function restoreSnapshot(string $name): void
    {
        $snapshot = app(DatabaseSnapshot::class);

        if (!$snapshot->exists($name)) {
            throw new \Exception("Snapshot '{$name}' não encontrado. Crie com: php artisan snapshot:create {$name}");
        }

        $snapshot->restore($name);
    }

    /**
     * Cria um snapshot do estado atual (útil no setUp de testes de integração).
     */
    protected function createSnapshot(string $name): void
    {
        $snapshot = app(DatabaseSnapshot::class);
        $snapshot->create($name);
    }

    /**
     * Wrapper para testes que restaura snapshot automaticamente.
     */
    protected function withSnapshot(string $name, callable $callback): void
    {
        $this->restoreSnapshot($name);
        $callback();
    }
}
