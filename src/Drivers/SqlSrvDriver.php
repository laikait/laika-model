<?php

declare(strict_types=1);

namespace Laika\Model\Drivers;

class SqlSrvDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'sqlsrv';
    }

    public function buildDsn(array $config): string
    {
        $host   = $this->getHost($config);
        $port   = $this->getPort($config, 1433);
        $dbname = $config['database'] ?? '';

        $server = isset($config['port']) ? "{$host},{$port}" : $host;

        $dsn = "sqlsrv:Server={$server};Database={$dbname}";

        if (!empty($config['encrypt'])) {
            $dsn .= ';Encrypt=1';
        }

        if (!empty($config['trust_server_certificate'])) {
            $dsn .= ';TrustServerCertificate=1';
        }

        return $dsn;
    }
}
