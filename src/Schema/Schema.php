<?php

declare(strict_types=1);

namespace Laika\Model\Schema;

use PDO;
use Laika\Model\Connection;
use Laika\Model\Exceptions\SchemaException;
use Laika\Model\Schema\Grammars\Grammar;
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
 *   Schema::drop('users');
 *   Schema::dropIfExists('users');
 *   Schema::hasTable('users');
 *   Schema::hasColumn('users', 'email');
 *   Schema::table('users', function (Blueprint $table) {
 *       $table->string('phone')->nullable();
 *   });
 */
class Schema
{
    private string $connection = 'default';

    /** @var array<string, class-string<Grammar>> */
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
    }

    // -----------------------------------------------------------------------
    // Static entry points
    // -----------------------------------------------------------------------

    /** Select a specific connection for schema operations. */
    public static function on(string $connection): self
    {
        return new self($connection);
    }

    // Proxy static calls to a default-connection instance
    public static function __callStatic(string $method, array $args): mixed
    {
        return (new self('default'))->$method(...$args);
    }

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
        $sql = $this->grammar()->compileCreate($blueprint);
        $this->pdo()->exec($sql);
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

        if (in_array($driver, ['sqlite', 'sqlite3'])) {
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
     */
    public function hasColumn(string $table, string $column): bool
    {
        $pdo    = $this->pdo();
        $sql    = $this->grammar()->compileColumnExists();
        $stmt   = $pdo->prepare($sql);
        $driver = $this->driverName();

        if (in_array($driver, ['sqlite', 'sqlite3'])) {
            // pragma_table_info(tableName) — args are (table, column)
            $stmt->execute([$table, $column]);
        } else {
            $db = $this->config()['database'] ?? '';
            $stmt->execute([$db, $table, $column]);
        }

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Execute raw SQL.
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
     *
     * @param string $driver e.g. "oci"
     * @param class-string<Grammar> $grammarClass
     */
    public static function registerGrammar(string $driver, string $grammarClass): void
    {
        if (!is_a($grammarClass, Grammar::class, true)) {
            throw new SchemaException("Grammar must extend " . Grammar::class);
        }
        self::$grammarMap[strtolower($driver)] = $grammarClass;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function pdo(): PDO
    {
        return Connection::get($this->connection);
    }

    private function config(): array
    {
        // Retrieve raw config via reflection – Connection stores it statically
        $ref = new \ReflectionClass(Connection::class);
        $prop = $ref->getProperty('configs');
        $prop->setAccessible(true);
        return $prop->getValue()[$this->connection] ?? [];
    }

    private function driverName(): string
    {
        return strtolower($this->config()['driver'] ?? 'mysql');
    }

    private function grammar(): Grammar
    {
        $driver = $this->driverName();

        if (isset(self::$grammarMap[$driver])) {
            return new self::$grammarMap[$driver]();
        }

        // Fallback to MySQL-compatible grammar
        return new MySqlGrammar();
    }
}
