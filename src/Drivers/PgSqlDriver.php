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
