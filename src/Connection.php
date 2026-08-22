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
 *
 * Every method that accepts a connection name resolves `null` to the current
 * default name, which is 'default' unless changed via {@see self::setDefault()}.
 *
 * Driver names reported by {@see self::driver()} are always *canonical*
 * (mysql, pgsql, sqlite, sqlsrv, oci, firebird) even when the config used an
 * alias such as 'mariadb', 'postgres', 'sqlite3', 'oracle' or 'ibase'.
 */
final class Connection
{
    /** Timezone identifiers/offsets accepted by {@see self::applyTimezone()}. */
    private const TIMEZONE_PATTERN = '/^[A-Za-z0-9_\/+\-:]+$/';

    /** @var array<string,array> Stored Configs, Keyed By Connection Name */
    private static array $configs = [];

    /** @var array<string,PDO> Live PDO instances (created lazily) */
    private static array $instances = [];

    /** @var array<string,string> Canonical driver name, keyed by connection name */
    private static array $drivers = [];

    /** @var array<string,int> Transaction / savepoint depth, keyed by connection name */
    private static array $transactions = [];

    /** @var string Name used whenever a caller omits the connection name */
    private static string $default = 'default';

    /**
     * Monotonic counter bumped on every registry mutation. Consumers that cache
     * a PDO handle (see Model) compare against it to detect a stale handle.
     * Never reset — a reused value would hide an invalidation.
     */
    private static int $generation = 0;

    // Prevent instantiation
    private function __construct() {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Register a Connection Config.
     *
     * @param (
     * array{
     *      driver: 'sqlite',
     *      database?: string,
     *      path?: string,
     *      password?: string,
     *      options?: array
     * }|
     * array{
     *      driver: 'mysql',
     *      host: string,
     *      database: string,
     *      port?: int,
     *      username?: string,
     *      password?: string,
     *      charset?: string,
     *      timezone?: string,
     *      unix_socket?: string,
     *      options?: array
     *      }|
     * array{
     *      driver: 'mariadb',
     *      host: string,
     *      database: string,
     *      port?: int,
     *      username?: string,
     *      password?: string,
     *      charset?: string,
     *      timezone?: string,
     *      unix_socket?: string,
     *      options?: array
     * }|
     * array{
     *      driver: 'pgsql',
     *      host: string,
     *      database: string,
     *      port?: int,
     *      username?: string,
     *      password?: string,
     *      charset?: string,
     *      options?: array
     * }|
     * array{
     *      driver: 'sqlsrv',
     *      host: string,
     *      database: string,
     *      port?: int,
     *      username?: string,
     *      password?: string,
     *      charset?: string,
     *      options?: array
     * }|
     * array{
     *      driver: 'firebird',
     *      host: string,
     *      database: string,
     *      port?: int,
     *      username?: string,
     *      password?: string,
     *      charset?: string,
     *      options?: array
     * }|
     * array{
     *      driver: 'oci',
     *      host: string,
     *      database: string,
     *      port?: int,
     *      username?: string,
     *      password?: string,
     *      charset?: string,
     *      options?: array
     * }
     * ) $config Database connection configuration.
     * @param ?string $name Connection name (default: the current default name).
     * @throws ConnectionException
     * @return void
     */
    public static function add(array $config, ?string $name = null): void
    {
        if (empty($config['driver'])) {
            throw new ConnectionException("Config Must Contain A 'driver' Key.");
        }

        $name = self::resolve($name);

        self::$configs[$name] = $config;

        // If a live instance already exists for this name, drop it so it
        // will be re-created with the new config on next get(). This also
        // clears the memoised driver name, which may now differ.
        self::close($name);
    }

    /**
     * Retrieve (and lazily create) a PDO connection by name.
     *
     * @param ?string $name Connection name (default: the current default name).
     * @throws ConnectionException
     * @return PDO
     */
    public static function get(?string $name = null): PDO
    {
        $name = self::resolve($name);

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
    public static function has(?string $name = null): bool
    {
        return isset(self::$configs[self::resolve($name)]);
    }

    /**
     * Return the raw config registered for a connection.
     *
     * Returns an empty array when the name is not registered.
     *
     * @return array<string,mixed>
     */
    public static function config(?string $name = null): array
    {
        return self::$configs[self::resolve($name)] ?? [];
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
     * Change the name used when a connection name is omitted.
     *
     * @throws ConnectionException When no config is registered under $name.
     */
    public static function setDefault(string $name): void
    {
        if (!isset(self::$configs[$name])) {
            throw new ConnectionException(
                "Cannot set default to [{$name}] — no connection config registered for it."
            );
        }

        self::$default = $name;
    }

    /**
     * Return the name currently used when a connection name is omitted.
     */
    public static function getDefault(): string
    {
        return self::$default;
    }

    /**
     * Close (destroy) a named connection.
     *
     * Drops the live PDO instance, the memoised driver name and any tracked
     * transaction depth, so the next get() rebuilds from the stored config.
     */
    public static function close(?string $name = null): void
    {
        $name = self::resolve($name);

        unset(self::$instances[$name], self::$drivers[$name], self::$transactions[$name]);
        self::$generation++;
    }

    /**
     * Close and immediately re-establish a connection.
     *
     * @throws ConnectionException
     */
    public static function reconnect(?string $name = null): PDO
    {
        $name = self::resolve($name);

        self::close($name);

        return self::get($name);
    }

    /**
     * Close all connections (useful for testing / reset).
     */
    public static function closeAll(): void
    {
        self::$instances    = [];
        self::$drivers      = [];
        self::$transactions = [];
        self::$generation++;
    }

    /**
     * Purge everything (configs + connections + default name).  Mainly for testing.
     */
    public static function purge(): void
    {
        self::$configs      = [];
        self::$instances    = [];
        self::$drivers      = [];
        self::$transactions = [];
        self::$default      = 'default';
        self::$generation++;
    }

    /**
     * Registry generation counter.
     *
     * Bumped on every mutation (add / close / closeAll / purge / reconnect).
     * Consumers caching a PDO handle can compare a stored value against this to
     * detect that the registry moved on underneath them.
     */
    public static function generation(): int
    {
        return self::$generation;
    }

    /**
     * Get the canonical driver name for a connection.
     *
     * Resolvable before the connection is established — it is derived from the
     * registered config, then memoised.
     *
     * @param ?string $name Connection Name for Driver
     * @throws ConnectionException When no config is registered under $name.
     * @return string One of: mysql, pgsql, sqlite, sqlsrv, oci, firebird, or a
     *                custom driver's getName().
     */
    public static function driver(?string $name = null): string
    {
        $name = self::resolve($name);

        if (isset(self::$drivers[$name])) {
            return self::$drivers[$name];
        }

        if (!isset(self::$configs[$name])) {
            throw new ConnectionException(
                "No connection config registered for [{$name}]."
            );
        }

        return self::$drivers[$name] = DriverFactory::make(self::$configs[$name])->getName();
    }

    /**
     * Apply Database Timezone
     *
     * @param string $timezone Timezone name or offset, e.g. 'UTC', 'Asia/Dhaka', '+06:00'.
     * @param ?string $name Connection name (default: the current default name).
     * @throws ConnectionException When $timezone is not a valid identifier/offset.
     * @return void
     */
    public static function applyTimezone(string $timezone, ?string $name = null): void
    {
        $timezone = self::assertTimezone($timezone);
        $name     = self::resolve($name);

        $pdo    = self::get($name);
        $driver = self::driver($name);

        // driver() reports canonical names, so aliases never reach this match.
        $sql = match ($driver) {
            'mysql', 'mariadb'  =>  "SET time_zone = '{$timezone}'",
            'pgsql'             =>  "SET TIME ZONE '{$timezone}'",
            'firebird'          =>  "SET TIME ZONE '{$timezone}'",
            'oci'               =>  "ALTER SESSION SET TIME_ZONE = '{$timezone}'",
            default             =>  null,
        };

        if ($sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // Firebird < 4.0 or driver without timezone support — ignore
            }
        }
    }

    /**
     * Validate a timezone identifier before it is interpolated into SQL.
     *
     * Neither MySQL nor PostgreSQL accept a bound parameter in a SET statement,
     * so an allowlist is the only way to make this safe.
     *
     * @throws ConnectionException
     */
    public static function assertTimezone(string $timezone): string
    {
        if ($timezone === '' || !preg_match(self::TIMEZONE_PATTERN, $timezone)) {
            throw new ConnectionException("Invalid timezone [{$timezone}].");
        }

        return $timezone;
    }

    // -----------------------------------------------------------------------
    // Transactions
    // -----------------------------------------------------------------------

    /**
     * Current transaction depth for a connection. 0 means no active transaction.
     */
    public static function transactionLevel(?string $name = null): int
    {
        return self::$transactions[self::resolve($name)] ?? 0;
    }

    /**
     * Begin a transaction, or open a savepoint if one is already active.
     *
     * @throws ConnectionException
     */
    public static function beginTransaction(?string $name = null): void
    {
        $name  = self::resolve($name);
        $pdo   = self::get($name);
        $level = self::transactionLevel($name);

        if ($level === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec(self::savepointSql('create', $level, self::driver($name)));
        }

        self::$transactions[$name] = $level + 1;
    }

    /**
     * Commit the current transaction, or release the current savepoint.
     *
     * @throws ConnectionException
     */
    public static function commit(?string $name = null): void
    {
        $name  = self::resolve($name);
        $level = self::transactionLevel($name);

        if ($level === 0) {
            throw new ConnectionException("No active transaction on connection [{$name}].");
        }

        $pdo = self::get($name);

        if ($level === 1) {
            $pdo->commit();
        } else {
            $sql = self::savepointSql('release', $level - 1, self::driver($name));
            // SQL Server has no RELEASE SAVEPOINT — nested commits are a no-op there.
            if ($sql !== null) {
                $pdo->exec($sql);
            }
        }

        self::$transactions[$name] = $level - 1;
    }

    /**
     * Roll back the current transaction, or roll back to the current savepoint.
     *
     * @throws ConnectionException
     */
    public static function rollBack(?string $name = null): void
    {
        $name  = self::resolve($name);
        $level = self::transactionLevel($name);

        if ($level === 0) {
            throw new ConnectionException("No active transaction on connection [{$name}].");
        }

        $pdo = self::get($name);

        if ($level === 1) {
            $pdo->rollBack();
        } else {
            $pdo->exec(self::savepointSql('rollback', $level - 1, self::driver($name)));
        }

        self::$transactions[$name] = $level - 1;
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /** Resolve an optional connection name to a concrete one. */
    private static function resolve(?string $name): string
    {
        return $name ?? self::$default;
    }

    /**
     * Build the savepoint statement for a driver.
     *
     * @param 'create'|'release'|'rollback' $action
     * @return ?string Null when the driver has no equivalent statement.
     */
    private static function savepointSql(string $action, int $index, string $driver): ?string
    {
        $point = "laika_sp{$index}";

        // SQL Server uses its own dialect and has no RELEASE equivalent.
        if ($driver === 'sqlsrv') {
            return match ($action) {
                'create'   => "SAVE TRANSACTION {$point}",
                'rollback' => "ROLLBACK TRANSACTION {$point}",
                default    => null,
            };
        }

        return match ($action) {
            'create'   => "SAVEPOINT {$point}",
            'release'  => "RELEASE SAVEPOINT {$point}",
            'rollback' => "ROLLBACK TO SAVEPOINT {$point}",
            default    => null,
        };
    }

    private static function createPdo(string $name): PDO
    {
        $config = self::$configs[$name];
        $driver = DriverFactory::make($config);
        $dsn = $driver->buildDsn($config);
        $options = $driver->getOptions($config);
        self::$drivers[$name] = $driver->getName();
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;

        try {
            return new PDO($dsn, $username, $password, $options);
        } catch (\PDOException $e) {
            throw new ConnectionException(
                "Failed to connect [{$config['driver']}]: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }
    }
}
