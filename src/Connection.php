<?php

declare(strict_types = 1);

namespace Laika\Model;

use PDO;
use PDOException;

// Model Class
class Connection
{
    /**
     * Hold PDO Database Connections
     * @var array $connections
     */
    private static array $connections = [];

    /**
     * Driver Names
     * @var array<string,string> $drivers Example: ['defaulr'=>'mysql']
     */
    private static array $drivers = [];

    /**
     * Add PDO Database Connection
     * @param array{driver?:string,host?:string,port?:string|int,database?:string,username?:string,password?:string} $config
     * @param string $name Default is 'default'
     * @return void
     */
    public static function add(array $config, string $name = 'default'): void
    {
        // Throw Error if Connetion Already Exists
        if (self::has($name)) {
            throw new PDOException("Connection '{$name}' Already Exists!");
        }
    
        // Get Config
        $obj = new Config($config);
        // Add Connection
        self::$connections[$name] =$obj->pdo();
        // Add Driver Name
        self::$drivers[$name] = strtolower($obj->driver());
        return;
    }

    /**
     * PDO Database Connection Name
     * @param string $name
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$connections[$name]);
    }

    /**
     * Get PDO Database Connection Object
     * @param string $name
     * @return PDO
     */
    public static function get(string $name = 'default'): PDO
    {
        if (!self::has($name)) {
            throw new PDOException("Invalid PDO Connection Called: '{$name}'!", );
        }
        return self::$connections[$name];
    }

    /**
     * Get All Connections
     * @return array
     */
    public static function all(): array
    {
        return self::$connections;
    }

    /**
     * Get Driver Name
     * @return string|PDOException
     */
    public static function driver(string $name = 'default'): string
    {
        if (!isset(self::$drivers[$name])) {
            throw new PDOException("Driver [{$name}] Doesn't Exists!");
        }
        return self::$drivers[$name];
    }
}
