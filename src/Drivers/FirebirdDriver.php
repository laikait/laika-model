<?php

declare(strict_types=1);

namespace Laika\Model\Drivers;

class FirebirdDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'firebird';
    }

    public function buildDsn(array $config): string
    {
        $host    = $this->getHost($config);
        $port    = $this->getPort($config, 3050);
        $dbname  = $config['database'] ?? '';
        $charset = $this->getCharset($config, 'UTF8');

        return "firebird:dbname={$host}/{$port}:{$dbname};charset={$charset}";
    }
}
