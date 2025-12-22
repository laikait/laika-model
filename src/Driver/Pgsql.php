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

namespace Laika\Model\Driver;

use InvalidArgumentException;
use Laika\Model\Abstracts\DriverBlueprint;

class Pgsql extends DriverBlueprint
{
    private string $host;
    private int $port;
    private string $database;

    /**
     * @param array{host:string,port?:int,database:string} $config
     */
    public function __construct(array $config)
    {
        // Check Database Name Key Exists
        if (empty($config['database'])) {
            throw new InvalidArgumentException('[database] Key Not Found in Config!');
        }

        $this->host = $config['host'] ?? 'localhost';
        $this->port = (int) ($config['port'] ?? 5432);
        $this->database = $config['database'];
    }

    public function dsn(): string
    {
        return "pgsql:host={$this->host};port={$this->port};dbname={$this->database}";
    }
}