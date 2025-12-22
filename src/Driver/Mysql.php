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

class Mysql extends DriverBlueprint
{
    /**
     * Database Host
     * @var string $host
     */
    private string $host;

    /**
     * Database Port
     * @var int $port
     */
    private int $port;

    /**
     * Database Name
     * @var string $database
     */
    private string $database;

    /**
     * Database Charset
     * @var string $charset
     */
    private string $charset;


    /**
     * @param array{host?:string,port?:string|int,database:string,charset?:string} $config
     */
    public function __construct(array $config)
    {
        // Check Database Name Key Exists
        if (empty($config['database'])) {
            throw new InvalidArgumentException('[database] Key Not Found in Config!');
        }

        $this->host = $config['host'] ?? 'localhost';
        $this->port = (int) ($config['port'] ?? 3306);
        $this->database = $config['database'];
        $this->charset = $config['charset'] ?? 'utf8mb4';
    }

    public function dsn(): string
    {
        return "mysql:host={$this->host};port={$this->port};dbname={$this->database};charset={$this->charset}";
    }
}