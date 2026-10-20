<?php

namespace RiseTechApps\RiseTools\Features\DatabaseSnapshot\Exceptions;

/**
 * Binário do cliente do banco (pg_dump, psql, mysqldump, mysql) não está no
 * PATH. Lançada antes de rodar o processo, para não confundir com falhas da
 * própria ferramenta (senha errada, banco inexistente...).
 */
class ToolNotFoundException extends \RuntimeException
{
    public function __construct(public readonly string $tool)
    {
        parent::__construct("Tool not found in PATH: {$tool}");
    }

    public function client(): string
    {
        return in_array($this->tool, ['pg_dump', 'psql'], true) ? 'PostgreSQL client' : 'MySQL client';
    }

    /**
     * @return array<string, string> sistema => comando de instalação
     */
    public function installHints(): array
    {
        if (in_array($this->tool, ['pg_dump', 'psql'], true)) {
            return [
                'Debian/Ubuntu' => 'sudo apt-get install postgresql-client',
                'Alpine' => 'apk add postgresql-client',
            ];
        }

        return [
            'Debian/Ubuntu' => 'sudo apt-get install default-mysql-client',
            'Alpine' => 'apk add mysql-client',
        ];
    }
}
