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

namespace Laika\Model\Schema;

use PDO;
use Laika\Model\Connection;
use Laika\Model\Schema\Grammars\Grammar;
use Laika\Model\Exceptions\SchemaException;
use Laika\Model\Schema\Grammars\MySqlGrammar;
use Laika\Model\Schema\Grammars\PgSqlGrammar;
use Laika\Model\Schema\Grammars\SqlSrvGrammar;
use Laika\Model\Schema\Grammars\SqliteGrammar;

/**
 * Schema builder.
 *
 * Usage:
 *   Schema::create('users', function (Blueprint $table) {
 *       $table->id();
 *       $table->string('name');
 *       $table->timestamps();
 *   });
 *
 *   Schema::on('pgsql')->create('users', function (Blueprint $table) { ... });
 *
 *   Schema::on('pgsql')->drop('users');
 *   Schema::on('pgsql')->dropIfExists('users');
 *   Schema::on('pgsql')->hasTable('users');
 *   Schema::on('pgsql')->hasColumn('users', 'email');
 *   Schema::on('pgsql')->table('users', function (Blueprint $table) {
 *       $table->string('phone')->nullable();
 *   });
 */
final class Schema
{
    /** @var string $connection Database Connection Name */
    private string $connection = 'default';

    /**
     * Keyed by *canonical* driver name (see Connection::driver()). Aliases such
     * as 'mariadb' or 'sqlite3' are normalised away before lookup.
     *
     * @var array<string, class-string<Grammar>>
     */
    private static array $grammarMap = [
        'mysql'    => MySqlGrammar::class,
        'mariadb'  => MySqlGrammar::class,
        'pgsql'    => PgSqlGrammar::class,
        'postgres' => PgSqlGrammar::class,
        'sqlsrv'   => SqlSrvGrammar::class,
        'sqlite'   => SqliteGrammar::class,
        'sqlite3'  => SqliteGrammar::class,
    ];

    private function __construct(string $connection)
    {
        $this->connection = $connection;

        // Optional host-framework integration — neither Init nor Config is a
        // declared dependency, so both must be guarded before use.
        if (class_exists("\\Laika\\Service\\Init")) {
            \Laika\Service\Init::db($this->connection);
            return;
        }

        if (Connection::has($this->connection)) {
            return;
        }

        if (class_exists("\\Laika\\Service\\Config")) {
            // Register under the requested name, not 'default' — otherwise
            // Schema::on('analytics') would configure the wrong connection.
            Connection::add(\Laika\Service\Config::get('database', $this->connection), $this->connection);
            return;
        }

        throw new SchemaException(
            "No connection config registered for [{$this->connection}] — call Connection::add() first."
        );
    }

    // -----------------------------------------------------------------------
    // Static entry points
    // -----------------------------------------------------------------------

    /** Select a specific connection for schema operations. */
    public static function on(?string $connection = null): self
    {
        return new self($connection ?? Connection::getDefault());
    }

    // // Proxy static calls to a default-connection instance
    // public static function __callStatic(string $method, array $args): mixed
    // {
    //     return (new self('default'))->$method(...$args);
    // }

    // -----------------------------------------------------------------------
    // Operations
    // -----------------------------------------------------------------------

    /**
     * Create a new table.
     */
    public function create(string $table, \Closure $callback, array $options = []): void
    {
        $blueprint = new Blueprint($table, $options);
        $callback($blueprint);

        $grammar = $this->grammar();

        // Only MySQL accepts inline INDEX inside CREATE TABLE; every other
        // driver returns its indexes here as separate CREATE INDEX statements.
        $statements = array_merge(
            [$grammar->compileCreate($blueprint)],
            $grammar->compileIndexes($blueprint)
        );

        foreach ($statements as $sql) {
            try {
                $this->pdo()->exec($sql);
            } catch (\Throwable $th) {
                throw new SchemaException("Schema Error In Query [{$sql}]. {$th->getMessage()}.", (int) $th->getCode(), $th);
            }
        }
    }

    /**
     * Create a new table if it does not already exist.
     */
    public function createIfNotExists(string $table, \Closure $callback): void
    {
        $this->create($table, $callback, ['ifNotExists' => true]);
    }

    /**
     * Modify an existing table (add columns).
     */
    public function table(string $table, \Closure $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $sql = $this->grammar()->compileAddColumns($blueprint);
        $this->pdo()->exec($sql);
    }

    /**
     * Drop a table.
     */
    public function drop(string $table): void
    {
        $this->pdo()->exec($this->grammar()->compileDrop($table));
    }

    /**
     * Drop a table if it exists.
     */
    public function dropIfExists(string $table): void
    {
        $this->pdo()->exec($this->grammar()->compileDropIfExists($table));
    }

    /**
     * Rename a table.
     */
    public function rename(string $from, string $to): void
    {
        $this->pdo()->exec($this->grammar()->compileRenameTable($from, $to));
    }

    /**
     * Determine whether a table exists.
     */
    public function hasTable(string $table): bool
    {
        $pdo    = $this->pdo();
        $sql    = $this->grammar()->compileTableExists();
        $stmt   = $pdo->prepare($sql);
        $driver = $this->driverName();

        if ($driver === 'sqlite') {
            // sqlite_master query only needs the table name
            $stmt->execute([$table]);
        } else {
            $db = $this->config()['database'] ?? '';
            $stmt->execute([$db, $table]);
        }

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Determine whether a column exists on a table.
     * @param string $table
     * @param string $column
     * @return bool
     */
    public function hasColumn(string $table, string $column): bool
    {
        $pdo    = $this->pdo();
        $sql    = $this->grammar()->compileColumnExists();
        $stmt   = $pdo->prepare($sql);
        $driver = $this->driverName();

        if ($driver === 'sqlite') {
            // pragma_table_info(tableName) — args are (table, column)
            $stmt->execute([$table, $column]);
        } else {
            $db = $this->config()['database'] ?? '';
            $stmt->execute([$db, $table, $column]);
        }

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Execute raw SQL.
     * @return bool
     */
    public function statement(string $sql): bool
    {
        return (bool)$this->pdo()->exec($sql);
    }

    // -----------------------------------------------------------------------
    // Grammar registration
    // -----------------------------------------------------------------------

    /**
     * Register a custom grammar for a driver.
     * @param string $driver Canonical driver name as reported by
     * Connection::driver(), e.g. "oci" (not "oracle").
     * @param class-string<Grammar> $grammarClass
     */
    public static function registerGrammar(string $driver, string $grammarClass): void
    {
        if (!is_a($grammarClass, Grammar::class, true)) {
            throw new SchemaException("Grammar must extend " . Grammar::class);
        }
        self::$grammarMap[strtolower($driver)] = $grammarClass;
    }

    /**
     * Disable Foreign Key Checks
     * No-op on drivers with no session-wide switch (Oracle, Firebird) — there
     * the constraints must be disabled one at a time.
     * @return void
     */
    public function disableForeignKeyChecks(): void
    {
        $sql = $this->foreignKeyCheckSql(false);

        if ($sql === null) {
            return;
        }

        $this->pdo()->exec($sql);
    }

    /**
     * Enable Foreign Key Checks
     * No-op on drivers with no session-wide switch — see
     * disableForeignKeyChecks().
     * @return void
     */
    public function enableForeignKeyChecks(): void
    {
        $sql = $this->foreignKeyCheckSql(true);

        if ($sql === null) {
            return;
        }

        $this->pdo()->exec($sql);
    }

    ######################################################################
    /*========================== INTERNAL API ==========================*/
    ######################################################################
    /**
     * The statement that toggles FK enforcement, or null where the driver has
     * no equivalent. Never guess — a MySQL default here is a syntax error on
     * Oracle and Firebird, both of which can otherwise connect and query fine.
     * @param bool $enabled
     * @return ?string
     */
    private function foreignKeyCheckSql(bool $enabled): ?string
    {
        return match ($this->driverName()) {
            'mysql'     =>  'SET FOREIGN_KEY_CHECKS = ' . ($enabled ? '1' : '0'),
            'mariadb'   =>  'SET FOREIGN_KEY_CHECKS = ' . ($enabled ? '1' : '0'),
            'pgsql'     =>  'SET session_replication_role = ' . ($enabled ? 'DEFAULT' : 'replica'),
            'sqlite'    =>  'PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF'),
            'sqlite3'   =>  'PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF'),
            'sqlsrv'    =>  $enabled ?
                                'EXEC sp_msforeachtable "ALTER TABLE ? WITH CHECK CHECK CONSTRAINT ALL"' :
                                'EXEC sp_msforeachtable "ALTER TABLE ? NOCHECK CONSTRAINT ALL"',
            default             =>  null,
        };
    }

    /**
     * Get PDO Connection
     * @return PDO
     */
    private function pdo(): PDO
    {
        return Connection::get($this->connection);
    }

    /**
     * Get Config
     * @return array
     */
    private function config(): array
    {
        return Connection::config($this->connection);
    }

    /**
     * Canonical driver name — never an alias
     * @return string
     */
    private function driverName(): string
    {
        return Connection::driver($this->connection);
    }

    /**
     * Get Grammar
     * @return Grammar
     * @throws SchemaException
     */
    private function grammar(): Grammar
    {
        $driver = $this->driverName();

        if (isset(self::$grammarMap[$driver])) {
            return new self::$grammarMap[$driver]();
        }

        // Never guess a dialect — a wrong grammar produces SQL that either fails
        // obscurely or, worse, succeeds against the wrong server.
        throw new SchemaException("No schema grammar registered for driver [{$driver}]. Use Schema::registerGrammar() to add one.");
    }
}
