<?php
/**
 * Laika Database Model
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

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
