<?php

declare(strict_types=1);

namespace Laika\Model;

use PDO;
use Laika\Model\Drivers\DriverFactory;
use Laika\Model\Exceptions\ConnectionException;

/**
 * Connection manager.
 *
 * Usage:
 *   Connection::add(['driver' => 'mysql', 'host' => '...', ...]);
 *   Connection::add(['driver' => 'pgsql', 'host' => '...', ...], 'read');
 *   $pdo = Connection::get();          // default
 *   $pdo = Connection::get('read');
 */
final class Connection
{
    /** @var array<string,array> Stored Configs, Keyed By Connection Name */
    private static array $configs = [];

    /** @var array<string,PDO> Live PDO instances (created lazily) */
    private static array $instances = [];

    /** @var array<string,string> */
    private array $drivers = [];

    // Prevent instantiation
    private function __construct() {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Register a Connection Config.
     *
     * @param array  $config Must contain a 'driver' key.
     * @param string $name Connection name (default: 'default').
     * @return void
     */
    public static function add(array $config, string $name = 'default'): void
    {
        if (empty($config['driver'])) {
            throw new ConnectionException("Config Must Contain A 'driver' Key.");
        }

        self::$configs[$name] = $config;

        // If a live instance already exists for this name, drop it so it
        // will be re-created with the new config on next get().
        self::close($name);
    }

    /**
     * Retrieve (and lazily create) a PDO connection by name.
     *
     * @param string $name Connection name (default: 'default').
     * @throws ConnectionException
     * @return PDO
     */
    public static function get(string $name = 'default'): PDO
    {
        if (!self::has($name)) {
            throw new ConnectionException(
                "No connection config registered for [{$name}]."
            );
        }

        if (!isset(self::$instances[$name])) {
            self::$instances[$name] = self::createPdo($name);
        }

        return self::$instances[$name];
    }

    /**
     * Check if a named connection config exists.
     */
    public static function has(string $name = 'default'): bool
    {
        return isset(self::$configs[$name]);
    }

    /**
     * Return all registered connection names.
     *
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::$configs);
    }

    /**
     * Close (destroy) a named connection.
     */
    public static function close(string $name = 'default'): void
    {
        unset(self::$instances[$name]);
    }

    /**
     * Close all connections (useful for testing / reset).
     */
    public static function closeAll(): void
    {
        self::$instances = [];
    }

    /**
     * Purge everything (configs + connections).  Mainly for testing.
     */
    public static function purge(): void
    {
        self::$configs   = [];
        self::$instances = [];
    }

    /**
     * Get Connection Driver Name
     * @param string $name Connection Name for Driver
     * @return string
     */
    public static function driver(string $name): string
    {
        if (!isset(self::$drivers[$name])) {
            throw new ConnectionException("Connection [{$name}] Initiate First.");
        }
        return self::$drivers[$name];
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    private static function createPdo(string $name): PDO
    {
        $config = self::$configs[$name];
        $driver  = DriverFactory::make($config);
        $dsn     = $driver->buildDsn($config);
        $options = $driver->getOptions($config);
        self::$drivers[$name] = $driver->getName();

        try {
            return new PDO(
                $dsn,
                $config['username'] ?? null,
                $config['password'] ?? null,
                $options
            );
        } catch (\PDOException $e) {
            throw new ConnectionException(
                "Failed to connect [{$config['driver']}]: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }
    }
}
