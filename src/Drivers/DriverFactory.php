<?php

declare(strict_types=1);

namespace Laika\Model\Drivers;

use Laika\Model\Exceptions\DriverException;

class DriverFactory
{
    /** Canonical alias map → driver class */
    private static array $map = [
        'mysql'    => MySqlDriver::class,
        'mariadb'  => MySqlDriver::class,   // MariaDB is MySQL-compatible
        'pgsql'    => PgSqlDriver::class,
        'postgres' => PgSqlDriver::class,
        'sqlsrv'   => SqlSrvDriver::class,
        'oci'      => OciDriver::class,
        'oracle'   => OciDriver::class,
        'firebird' => FirebirdDriver::class,
        'ibase'    => FirebirdDriver::class,
        'sqlite'   => SqliteDriver::class,
        'sqlite3'  => SqliteDriver::class,
    ];

    /** Custom drivers registered by the user */
    private static array $custom = [];

    /**
     * Register a custom driver.
     *
     * @param string $alias   e.g. "mydb"
     * @param class-string<DriverInterface> $class
     */
    public static function register(string $alias, string $class): void
    {
        if (!is_a($class, DriverInterface::class, true)) {
            throw new DriverException("Driver class [{$class}] must implement DriverInterface.");
        }
        self::$custom[strtolower($alias)] = $class;
    }

    /**
     * Resolve a driver instance from a config array.
     */
    public static function make(array $config): DriverInterface
    {
        $driver = strtolower($config['driver'] ?? '');

        if (isset(self::$custom[$driver])) {
            return new self::$custom[$driver]();
        }

        if (isset(self::$map[$driver])) {
            return new self::$map[$driver]();
        }

        throw new DriverException(
            "Unsupported driver [{$driver}]. Supported: " . implode(', ', array_keys(self::$map))
        );
    }

    /** Return all known driver aliases. */
    public static function supported(): array
    {
        return array_keys(array_merge(self::$map, self::$custom));
    }
}
