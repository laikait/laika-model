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
        $blueprint = new Blueprint($this->table, $this->driver);

        $callback($blueprint);

        $blueprint->create();
        $this->sqls = $blueprint->sqls();
        return $this;
    }

    /**
     * Alter Table Sql Query
     * @param string $table Required Argument
     * @param callable $callback Callback With Blueprint. Example function(Blueprint $table){.....}
     * @return self
     */
    public function alter(callable $callback): self
    {
        $blueprint = new Blueprint($this->table, $this->driver);

        $callback($blueprint);

        $blueprint->alter();
        $this->sqls = $blueprint->sqls();
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

        // $stmt = $this->pdo->prepare($sql);

        // $stmt->execute();
        return $this;
    }

    /**
     * Sanitize Name
     * @param string $table Table/Column Name. Example: 'user'
     * @return string
     */
    private function sanitize(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
    }

    /**
     * Execute Queries
     * @return string
     */
    public function execute(): string
    {
        // Set Queries
        Log::add($this->sqls, $this->connection);

        // Execute SQL
        try {
            foreach ($this->sqls as $sql) {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute();
            };
        } catch (Throwable $th) {
            throw new PDOException("Error in Query: [{$sql}]", 10100, $th);
        }
        return implode("\n", $this->sqls);
    }
}