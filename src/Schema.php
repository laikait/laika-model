<?php

/**
 * Laika Database Model
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Model;

use PDO;
use Throwable;
use PDOException;
use Laika\Model\Compile\Drop;
use Laika\Model\Compile\Quote;
use Laika\Model\Compile\Rename;
use Laika\Model\Compile\Columns;

class Schema
{
    /**
     * @var PDO $pdo
     */
    protected PDO $pdo;

    /**
     * @var string $driver
     */
    protected string $driver;

    /**
     * Table Name
     * @var string $table
     */
    protected string $table;

    /**
     * Index Keys
     * @var array<int|string> $indexes
     */
    protected array $indexes = [];

    /**
     * Unique Keys
     * @var array<int|string> $uniques
     */
    protected array $uniques = [];

    /**
     * @var array $sqls
     */
    protected array $sqls = [];

    /**
     * Connection Name
     * @var string $connection
     */
    protected string $connection;

    /**
     * Constructor. Blocked to enforce static usage.
     * @param string $connection PDO Connection Name. Example: 'default'
     */
    private function __construct(string $connection = 'default')
    {
        $this->connection = $connection;
        $this->pdo = Connection::get($this->connection);
        $this->driver = Connection::driver($this->connection);
    }

    /*============================== PUBLIC API ==============================*/
    /**
     * Set Table
     * @param string $table Table Name. Example: 'user'
     * @param string $connection PDO Connection Name. Example: 'default'
     * @return Schema
     */
    public static function table(string $table, string $connection = 'default'): Schema
    {
        $obj = new self($connection);
        $obj->table = $obj->sanitize($table);
        return $obj;
    }

    /**
     * Create Table SQL Query
     * @param string $table Required Argument
     * @param callable $callback Callback With Blueprint. Example function(Blueprint $table){.....}
     * @return self
     */
    public function create(callable $callback): self
    {
        try {
            $blueprint = new Blueprint($this->table, $this->driver);
            $callback($blueprint);
            $blueprint->create();
            $this->sqls = $blueprint->sqls();
        } catch (\Throwable $th) {
            throw new Exceptions\SchemaException($th->getMessage());
        };
        return $this;
    }

    /**
     * Add Column to Table
     * @param string $table Required Argument
     * @param callable $callback Callback With Blueprint. Example function(Blueprint $table){.....}
     * @return self
     */
    public function addColumn(callable $callback): self
    {
        try {
            $blueprint = new Blueprint($this->table, $this->driver);
            $callback($blueprint);
            $blueprint->addColumn();
            $this->sqls = $blueprint->sqls();
        } catch (\Throwable $th) {
            throw new Exceptions\SchemaException($th->getMessage());
        }
        return $this;
    }

    /**
     * Delete Column From Table
     * @param string $table Required Argument
     * @param callable $callback Callback With Blueprint. Example function(Blueprint $table){.....}
     * @return self
     */
    public function dropColumn(string $column): self
    {
        $column = call_user_func([new Quote($this->sanitize($column), $this->driver), 'sql']);
        $table = call_user_func([new Quote($this->table, $this->driver), 'sql']);
        $this->sqls[] = "ALTER TABLE {$table} DROP {$column};";
        return $this;
    }

    /**
     * Drop Table
     * @param string $table Required Argument
     * @return self
     */
    public function drop(): self
    {
        $this->sqls[] = call_user_func([new Drop($this->table, $this->driver), 'sql']);
        return $this;
    }

    /**
     * Truncate Table
     * @return self
     */
    public function truncate(): self
    {
        $sql = match($this->driver) {
            'mysql' => "TRUNCATE TABLE {$this->table};",
            'pgsql' => "TRUNCATE TABLE {$this->table} RESTART IDENTITY CASCADE;",
            'sqlite' => "DELETE FROM {$this->table}; DELETE FROM sqlite_sequence WHERE name='{$this->table}';",
            default => "DELETE FROM {$this->table}"
        };
        
        $this->sqls[] = $sql;
        return $this;
    }

    /**
     * Rename Table
     * @param string $name New Table Name
     * @return self
     */
    public function rename(string $name): self
    {
        $name = $this->sanitize($name);
        $this->sqls[] = call_user_func([new Rename($this->driver, $this->table, $name), 'sql']);

        return $this;
    }

    /**
     * Run Multiple Schema Operations Safely
     * @param callable $callable Any Callable
     * @return void
     */
    public static function batch(callable $callback): void
    {
        $callback();
    }

    /**
     * Get All Tables in Database
     * @return array
     */
    public static function tables(string $connection = 'default'): array
    {
        $pdo = Connection::get($connection);
        $driver = Connection::driver($connection);
        
        $sql = match($driver) {
            'mysql' => "SHOW TABLES",
            'pgsql' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table'",
            default => throw new PDOException("Unsupported Driver: [{$driver}]")
        };
        
        $stmt = $pdo->query($sql);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return $tables;
    }

    /**
     * Check if Column Exists in Table
     * @return bool
     */
    public function hasColumn(string $column): bool
    {
        $columns = $this->columns();
        
        foreach ($columns as $col) {
            $fieldName = match($this->driver) {
                'mysql' => $col['Field'] ?? '',
                'pgsql' => $col['column_name'] ?? '',
                'sqlite' => $col['name'] ?? '',
                default => ''
            };
            
            if ($fieldName === $column) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get Columns Information for Table
     * @return array
     */
    public function columns(): array
    {
        $sql = (new Columns($this->driver, $this->table))->sql();

        $stmt = $this->pdo->query($sql);
        
        return $stmt->fetchAll();
    }

    /**
     * Preview Table Structure Without Executing
     * @param callable $callback Callback With Blueprint. Example function(Blueprint $table){.....}
     * @return array
     */
    public function preview(callable $callback): array
    {
        $blueprint = new Blueprint($this->table, $this->driver);
        $callback($blueprint);
        $blueprint->create();
        
        return $blueprint->sqls();
    }

    /**
     * Execute Queries
     * @return string
     */
    public function execute(): string
    {
        if (empty($this->sqls)) {
            throw new \InvalidArgumentException("No SQL Queries to Execute");
        }
        
        try {
            foreach ($this->sqls as $sql) {
                Log::add($sql, $this->connection);
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
            }            
        } catch (Throwable $th) {
            throw new Exceptions\SchemaException("Schema Execution Failed: [{$sql}]. Error: {$th->getMessage()}");
        }
        
        return implode("\n", $this->sqls);
    }

    /*============================== INTERNAL API ==============================*/
    /**
     * Sanitize Name
     * @param string $table Table/Column Name. Example: 'user'
     * @return string
     */
    private function sanitize(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    }
}