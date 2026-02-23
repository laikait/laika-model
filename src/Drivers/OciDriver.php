<?php

declare(strict_types=1);

namespace Laika\Model\Drivers;

class OciDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'oci';
    }

    public function buildDsn(array $config): string
    {
        // Support full connection string (tns) or host/port/service
        if (isset($config['tns'])) {
            return "oci:dbname={$config['tns']}";
        }

        $host    = $this->getHost($config);
        $port    = $this->getPort($config, 1521);
        $service = $config['service_name'] ?? ($config['database'] ?? '');

        return "oci:dbname=//{$host}:{$port}/{$service};charset={$this->getCharset($config, 'AL32UTF8')}";
    }
}
