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
