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

namespace Laika\Model;

use PDO;

class Config
{
    /**
     * PDO Database Connection Config Parameters
     * @var array{driver:string,host:string,port:string|int,database:string,username:string,password:string,options?:array} $config
     * @return void
     */
    private array $config;

    /**
     * PDO Object
     * @var PDO $pdo
     */
    protected PDO $pdo;

    /**
     * Driver Name
     * @var string $driver
     */
    protected string $driver;

    /**
     * Create PDO Database Connection
     * @param array{driver:string,host:string,port?:string|int,database?:string,username?:string,password?:string,options?:array} $config
     */
    public function __construct(array $config)
    {
        // Check Host Key Exists
        if (empty($config['host'])) {
            throw new \InvalidArgumentException('[host] Key Not Found in Config!');
        }
        // Check Driver Key Exists
        if (empty($config['driver'])) {
            throw new \InvalidArgumentException('[driver] Key Not Found in Config!');
        }
        $this->config = $config;
        $this->pdo = $this->create();
    }

    /**
     * Get PDO Object
     * @return PDO
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Get Driver
     * @return string Driver Name
     */
    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * Create PDO Object
     * @return PDO
     */
    protected function create(): PDO
    {
        $this->driver = $this->config['driver'];

        if (!extension_loaded('pdo')) {
            throw new \RuntimeException("Extension Not Loaded: [pdo]");
        }

        if (empty($this->driver)) {
            throw new \InvalidArgumentException("PDO Driver Name Should Not Be Empty: '{$this->driver}'.");
        }

        $class = __NAMESPACE__ . '\\Driver\\' . ucfirst($this->driver);

        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Invalid PDO Driver Detected: [{$this->driver}]");
        }

        $obj = new $class($this->config);
        $dsn = $obj->dsn();

        $username   =   $this->config['username'] ?? null;
        $password   =   $this->config['password'] ?? null;
        $options    =   $this->config['options'] ?? [];

        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $options += $defaultOptions;
        return new PDO($dsn, $username, $password, $options);
    }
}