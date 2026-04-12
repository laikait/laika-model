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
