<?php

declare(strict_types=1);

namespace Laika\Model\Drivers;

class PgSqlDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'pgsql';
    }

    public function buildDsn(array $config): string
    {
        $host   = $this->getHost($config);
        $port   = $this->getPort($config, 5432);
        $dbname = $config['database'] ?? '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

        if (isset($config['sslmode'])) {
            $dsn .= ";sslmode={$config['sslmode']}";
        }

        return $dsn;
    }
}
