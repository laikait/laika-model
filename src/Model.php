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

use Laika\Model\Exceptions\ModelException;

class Model
{
    /**
     * @var \PDO $pdo PDO Database Connection Object
     */
    protected \PDO $pdo;

    /**
     * @var string $driver Database Driver (mysql, sqlite, pgsql, sqlsrv, oci, firebird.)
     */
    protected string $driver;

    /**
     * @var string $columns Selected Columns
     */
    protected string $columns = '*';

    /**
     * @var array $joins Join Clauses
     */
    protected array $joins = [];

    /**
     * @var array $wheres Where Clauses
     */
    protected array $wheres = [];

    /**
     * @var array $bindings Query Bindings
     */
    protected array $bindings = [];

    /**
     * @var array $groupBy Group By Clauses
     */
    protected array $groupBy = [];

    /**
     * @var array $orderBy Order By Clauses
     */
    protected array $orderBy = [];

    /**
     * @var ?int $limit Limit Clause
     */
    protected ?int $limit = null;

    /**
     * @var ?int $offset Offset Clause
     */
    protected ?int $offset = null;

    /**
     * @var array $having Having Clauses
     */
    protected array $having = [];

    /**
     * @var string $connection Connection Name
     */
    protected string $connection;

    /**
     * @var string $table Table Name
     */
    protected string $table;

    /**
     * @var string $id ID Column Name
     */
    protected string $id = 'id';

    /**
     * @var string $uid UID Column Name
     */
    protected string $uid = 'uid';

    /**
     * @var bool $softDelete
     */
    protected bool $softDelete = false;

    /**
     * @var string $deletedAtColumn
     */
    protected string $deletedAtColumn = 'deleted_at';

    ####################################################################
    /*------------------------- EXTERNAL API -------------------------*/
    ####################################################################

    public function __construct(?string $connection = null)
    {
        $this->connection = $connection ?: 'default';
        $this->pdo = Connection::get($this->connection);
        $this->driver = Connection::driver($this->connection);
    }

    /**
     * Get PDO Object
     * @return \PDO
     */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * // Table Name
     * @param string $table Required table name
     * @return Model
     */
    public function table(string $table): Model
    {
        $this->reset();
        $this->table = $table;
        return $this;
    }

    /**
     * Select
     * @param ?string $columns Column names. Default is null
     * @return Model
     */
    public function select(?string $columns = null): Model
    {
        if (empty($columns) || trim($columns) == '*') {
            $this->columns = '*';
            return $this;
        }
        // Add Backtick
        $array = explode(',', $columns);
        // Trim & Quote Columns
        $trimed = array_map(function($v) {
            $v = trim($v);
            return $this->quoteIdentifier($v);
        }, $array);
        $this->columns = implode(',', $trimed);
        return $this;
    }

    /**
     * Select Distinct Rows
     * @return Model
     */
    public function distinct(): Model
    {
        $this->columns = 'DISTINCT ' . $this->columns;
        return $this;
    }

    /**
     * Join Clause
     * @param string $table Required table name to join
     * @param string $first Required first column
     * @param string $operator Required operator
     * @param string $second Required second column
     * @param string $type Optional join type (LEFT, RIGHT, INNER)
     * @return Model
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'LEFT'): Model
    {
        $type = strtoupper($type);
        // Quote String
        $table = $this->quoteIdentifier($table);
        $first = $this->quoteIdentifier($first);
        $second = $this->quoteIdentifier($second);

        if (!in_array($type, ['LEFT', 'RIGHT', 'INNER'])) {
            throw new \InvalidArgumentException("Invalid join type: {$type}");
        }

        $this->joins[] = "{$type} JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Where Clause
     * @param array|string $where Required column name or array of column-value pairs
     * @param string $operator Optional operator (default: '=')
     * @param string $compare Optional comparison type (AND, OR)
     * @return Model
     */
    public function where(array $where, string $operator = '=', string $compare = 'AND'): Model
    {
        foreach ($where as $col => $val) {
            // Quote String
            $col = $this->quoteIdentifier($col);
            $this->addWhere("{$col} {$operator} ?", [$val], $compare);
        }
        return $this;
    }

    /**
     * Where Not Equal
     * @param array|string $where Required column name or array of column-value pairs
     * @param string $compare Optional comparison type (AND, OR)
     * @return Model
     */
    public function whereNot(array $where, string $compare = 'AND'): Model
    {
        return $this->where($where, '!=', $compare);
    }

    /**
     * Where In
     * @param string $column Required column name
     * @param array $values Required array of values to match
     * @param string $compare Optional comparison type (AND, OR)
     * @return Model
     */
    public function whereIn(string $column, array $values, string $compare = 'AND'): Model
    {
        // Quote String
        $column = $this->quoteIdentifier($column);

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->addWhere("{$column} IN ({$placeholders})", $values, $compare);
        return $this;
    }

    /**
     * Where Not In
     * @param string $column Required column name
     * @param array $values Required array of values to match
     * @param string $compare Optional comparison type (AND, OR)
     * @return Model
     */
    public function whereNotIn(string $column, array $values, string $compare = 'AND'): Model
    {
        $column = $this->quoteIdentifier($column);
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->addWhere("{$column} NOT IN ({$placeholders})", $values, $compare);
        return $this;
    }

    /**
     * Check Column is Null
     * @param string $column Required column name
     * @param string $compare Optional comparison type (AND, OR)
     * @return Model
     */
    public function isNull(string $column, string $compare = 'AND'): Model
    {
        // Quote String
        $column = $this->quoteIdentifier($column);

        $this->addWhere("{$column} IS NULL", [], $compare);
        return $this;
    }

    /**
     * Check Column is Not Null
     * @param string $column Required column name
     * @param string $compare Optional comparison type (AND, OR)
     * @return Model
     */
    public function notNull(string $column, string $compare = 'AND'): Model
    {
        // Quote String
        $column = $this->quoteIdentifier($column);

        $this->addWhere("{$column} IS NOT NULL", [], $compare);
        return $this;
    }

     /**
     * Between Clause
     * @param string $column Required column name
     * @param mixed $value1 Required first value
     * @param mixed $value2 Required second value
     * @param string $compare Optional comparison type (AND, OR)
     * @return Model
     */
    public function between(string $column, mixed $value1, mixed $value2, string $compare = 'AND'): Model
    {
        // Quote String
        $column = $this->quoteIdentifier($column);

        $this->addWhere("{$column} BETWEEN ? AND ?", [$value1, $value2], strtoupper($compare));
        return $this;
    }

    /**
     * Where Group
     * @param callable $callback Callback Function. Example: function(Model $model) {$model->where(...)}
     * @param string $compare Optional comparison type (AND, OR)
     * @return Model
     */
    public function whereGroup(callable $callback, string $compare = 'AND'): Model
    {
        $model = new self($this->connection);

        $callback($model);

        if (empty($model->wheres)) {
            return $this;
        }

        $wheres = implode(' ', $model->wheres);
        $prefix = empty($this->wheres) ? '' : (strtoupper($compare) === 'OR' ? 'OR ' : 'AND ');
        $this->wheres[] = "{$prefix}({$wheres})";
        $this->bindings = array_merge($this->bindings, $model->bindings);

        return $this;
    }

    /**
     * Group By Clause
     * @param string ...$columns Required columns to group by
     * @return Model
     */
    public function groupBy(string ...$columns): Model
    {
        $this->groupBy = array_map(function($column){
            // Quote String
            return $this->quoteIdentifier($column);
        }, $columns);
        return $this;
    }

    /**
     * Having Clause
     * @param string $column Example: 'id'
     * @param string $operator Example: '='
     * @param mixed $value Example: 1
     * @return Model
     */
    public function having(string $column, string $operator, mixed $value): Model
    {
        // Quote String
        $column = $this->quoteIdentifier($column);

        $this->having[]     =   "{$column} {$operator} ?";
        $this->bindings[]   =   $value;
        return $this;
    }

    /**
     * Order By Clause
     * @param string $column Required column name
     * @param string $direction Optional direction (ASC, DESC)
     * @throws \InvalidArgumentException Throws an exception if an invalid direction is provided
     * @return Model
     */
    public function order(string $column, string $direction = 'ASC'): Model
    {
        $direction = strtoupper($direction);
        // Check Direction
        // Edit Here =======================================
        if (!in_array($direction, ['ASC', 'DESC'])) {
            throw new \InvalidArgumentException("Invalid order direction: {$direction}");
        }
        // Quote String
        $column = $this->quoteIdentifier($column);

        $this->orderBy[] = "{$column} {$direction}";
        return $this;
    }

    /**
     * Limit Clause
     * @param int|string $limit Required limit
     * @return Model
     */
    public function limit(int|string $limit): Model
    {
        $this->limit = (int) $limit;
        return $this;
    }

    /**
     * Offset Clause
     * @param int|string $page Page Number. Default is Page Number 1
     * @return Model
     */
    public function offset(int|string $page = 1): Model
    {
        $offset = ((int)$page - 1) * (int) $this->limit;
        $this->offset = ($offset < 0) ? 0 : $offset;
        return $this;
    }

    /**
     * Get With Trashed Rows
     * @return Model
     */
    public function withTrash(): Model
    {
        $this->notNull($this->deletedAtColumn);
        return $this;
    }

    /**
     * Get Without Trashed Rows
     * @return Model
     */
    public function withoutTrash(): Model
    {
        $this->isNull($this->deletedAtColumn);
        return $this;
    }

    /**
     * Get Result
     * @return array{} Returns the results as an array
     */
    public function get(): array
    {
        $sql = $this->build();
        // Add Queries to Log
        Log::add($sql, $this->connection);

        // Execute Query
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);

        // Fetch Results
        $result = $stmt->fetchAll();
        $this->reset();
        return $result;
    }

    /**
     * Get First Result
     * @return array{} Returns the first result as an array
     */
    public function first(): array
    {
        $this->limit(1);
        $result = $this->get();
        $first = $result[0] ?? [];
        return $first;
    }

    /**
     * Count Column
     * @return int
     */
    public function count(): int
    {
        $this->columns = "COUNT({$this->columns}) as count";
        $sql = $this->build();
        // Add Queries to Log
        Log::add($sql, $this->connection);

        // Execute Query
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);
        $result = $stmt->fetch();

        // Reset Query Builder
        $this->reset();
        return (int) ($result['count'] ?? 0);
    }


    /**
     * Get Single Column Values as Array
     * @param string $column Column Name
     * @return array
     */
    public function pluck(string $column): array
    {
        $results = $this->select($column)->get();
        return array_column($results, $column);
    }

    /**
     * Check if records exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Get First or Fail
     * @throws \RuntimeException Throws an exception if no records are found
     * @return array
     */
    public function firstOrFail(): array
    {
        $result = $this->first();
        if (empty($result)) {
            throw new \RuntimeException("No Records Found");
        }
        return $result;
    }

    /**
     * Insert Row('s)
     * @param array{} $data Insert Row('s) Data. Example: ['name' => 'John', 'age' => 30] or [0 => ['name' => 'John'], ['name' => 'Doe']]
     * @throws \InvalidArgumentException|\RuntimeException
     * @return string|false Returns the last inserted ID
     */
    public function insert(array $data): string|false
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Cannot Insert Empty Rows.');
        }

        // Normalize input: detect single row vs multiple rows
        $isMultiple = isset($data[0]) && is_array($data[0]);

        $rows = $isMultiple ? $data : [$data];

        // Extract columns from first row
        $keys = array_keys($rows[0]);

        // Quote columns
        $columns = array_map(function ($column) {
            return $this->quoteIdentifier($column);;
        }, $keys);

        // Build placeholders
        $rowPlaceholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($rows), $rowPlaceholders));

        // Sanitize Table
        $this->table = $this->sanitize($this->table);

        // Build SQL
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES {$placeholders}";

        // Log query
        Log::add($sql, $this->connection);

        // Flatten bindings
        $bindings = [];
        foreach ($rows as $row) {
            // Ensure row structure consistency
            if (array_keys($row) !== $keys) {
                throw new \InvalidArgumentException('All insert rows must have identical columns.');
            }
            $bindings = array_merge($bindings, array_values($row));
        }

        // Execute
        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($bindings);
        } catch (\Throwable $th) {
            throw new \RuntimeException($th->getMessage());
        }

        // Reset builder state
        $this->reset();

        return $result ? $this->pdo->lastInsertId() : false;
    }

    /**
     * Chunk the Results
     * @param int $size Chunk Size. Example: 100
     * @param callable $callback Callback function. Argument is an array of results. Example: function(array $rows) { ... }
     * @throws \InvalidArgumentException Throws an exception if chunk size is invalid
     * @return void
     */
    public function chunk(int $size, callable $callback): void
    {
        if ($size <= 0) {
            throw new \InvalidArgumentException("Chunk Size Must be Greater Than 0, Got: [{$size}]");
        }

        $offset = 0;

        while (true) {
            // Select in chunks
            $sql = $this->build() . " LIMIT {$size} OFFSET {$offset}";
            // Add Queries to Log
            Log::add($sql, $this->connection);

            // Execute Query
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);

            // Fetch Results
            $results = $stmt->fetchAll();

            // Break if no results
            if (empty($results)) {
                break;
            }

            // Pass the results to the callback
            $callback($results);

            // Move offset forward
            $offset += $size;
        }
        $this->reset();
    }

    /**
     * Update Clause
     * @param array $data Required data to update
     * @throws \InvalidArgumentException Throws an exception if no WHERE clause is provided for the update operation
     * @return int Returns the number of affected rows
     */
    public function update(array $data): int
    {
        if (empty($this->wheres)) {
            throw new \InvalidArgumentException("No WHERE Clause Provided for UPDATE operation.");
        }
        $set = [];
        foreach (array_keys($data) as $column) {
            $column = $this->quoteIdentifier($column);
            $set[] = "{$column} = ?";
        }

        // Sanitize Table
        $this->table = $this->sanitize($this->table);

        // Make SQL
        $sql = "UPDATE {$this->table} SET " . implode(', ', $set);

        if (!empty($this->joins)) {
            $sql .= " " . implode(' ', $this->joins);
        }

        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' ', $this->wheres);
        }

        // Add Queries to Log
        Log::add($sql, $this->connection);

        // Execute Query
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(array_values($data), $this->bindings));

        // Get Affected Rows
        $rowcount = $stmt->rowCount();
        $this->reset();
        return $rowcount;
    }

    /**
     * Enable Soft Delete
     * @param bool $enable Default is true
     * @return Model
     */
    public function soft(bool $enable = true): Model
    {
        $this->softDelete = $enable;
        return $this;
    }

    /**
     * Delete Row(s)
     * @throws \InvalidArgumentException Throws an exception if no WHERE clause is provided for the delete operation
     * @return int Returns the number of affected rows
     */
    public function delete(): int
    {
        // Check Where Clause Exists
        if (empty($this->wheres)) {
            throw new \InvalidArgumentException("No WHERE Clause provided for DELETE operation.");
        }

        if ($this->softDelete) {
            return $this->update([$this->deletedAtColumn => date('Y-m-d H:i:s')]);
        }

        // Sanitize Table
        $this->table = $this->sanitize($this->table);

        // Make SQL
        $sql = "DELETE FROM {$this->table}";

        $sql .= " WHERE " . implode(' ', $this->wheres);

        // Add Queries to Log
        Log::add($sql, $this->connection);
    
        // Execute Query
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->bindings);

        // Get Affected Rows
        $rowcount = $stmt->rowCount();
        $this->reset();
        return $rowcount;
    }

    /**
     * Restore Row(s)
     * @throws \InvalidArgumentException Throws an exception if no WHERE clause is provided for the restore operation
     * @return int Returns the number of affected rows
     */
    public function restore(): int
    {
        // Check Where Clause Exists
        if (empty($this->wheres)) {
            throw new \InvalidArgumentException("No WHERE Clause provided for Restore operation.");
        }

        return $this->update([$this->deletedAtColumn => null]);
    }

    /**
     * Execute Raw Query With Automatic Return Type Detection
     * @param string $sql Raw SQL query
     * @param ?array $bindings Parameter bindings
     * @return \PDOStatement Returns array of rows for SELECT, affected rows for INSERT/UPDATE/DELETE
     */
    public function execute(string $sql, ?array $bindings = null): \PDOStatement
    {
        Log::add($sql, $this->connection);
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt;
    }

    /**
     * Debug SQL
     * @return string Returns the SQL query with bindings
     */
    public function debug(): string
    {
        $sql = $this->build();
        $bindings = $this->bindings;

        $sql = preg_replace_callback('/\?/', function () use (&$bindings) {
            $value = array_shift($bindings);
            if (is_numeric($value)) {
                return $value;
            }
            return "'" . addslashes($value) . "'";
        }, $sql);

        return $sql;
    }

    /**
     * Run a Transactional Callback
     * @param callable $callback Callback Function. Use Model as Argument. Example: function(Model $model) { ... }
     * @return mixed Returns the result of the callback
     * @throws \RuntimeException Throws an exception if the transaction fails
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new \RuntimeException("Transaction Failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate UID
     * @param string $prefix UID Prefix. Example: 'UID'
     * @param int $maxAttempts Maximum Try if UID Already Exists
     * @return string
     * @throws \RuntimeException
     */
    public function uid(string $prefix = 'uid', int $maxAttempts = 10): string
    {
        $prefix = empty($prefix) ? 'uid' : strtolower($prefix);
        
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $time = substr(str_replace('.', '', (string) microtime(true)), -6);
            $str1 = bin2hex(random_bytes(mt_rand(3,4)));
            $str2 = bin2hex(random_bytes(mt_rand(2,3)));
            $str3 = bin2hex(random_bytes(mt_rand(3,4)));
            $str4 = bin2hex(random_bytes(mt_rand(2,3)));
            $uid = "{$prefix}-{$str1}-{$str2}-{$str3}-{$str4}-{$time}";
            
            if (!$this->select($this->uid)->where([$this->uid => $uid])->first()) {
                return $uid;
            }
        }
        
        throw new \RuntimeException("Failed to Generate Unique UID After [{$maxAttempts}] Attempts");
    }

    ####################################################################
    /*------------------------- INTERNAL API -------------------------*/
    ####################################################################
    /**
     * Add Where Condition
     * @param string $condition Required condition string
     * @param array $bindings Optional bindings for the condition
     * @param string $compare Optional comparison type (AND, OR)
     * @return void
     */
    private function addWhere(string $condition, array $bindings = [], string $compare = 'AND'): void
    {
        $compare = strtoupper($compare);
        $prefix = empty($this->wheres) ? '' : ($compare === 'OR' ? 'OR ' : 'AND ');
        $this->wheres[] = "{$prefix}{$condition}";
        $this->bindings = array_merge($this->bindings, $bindings);
    }

    /**
     * Build the SQL Query
     * @throws \PDOException Throws an exception if the table name is not set
     * @return string Returns the built SQL query
     */
    private function build(): string
    {
        if (empty($this->table)) {
            throw new \PDOException("Table Name Not Found!");
        }

        // Sanitize Table
        $this->table = $this->sanitize($this->table);

        $sql = "SELECT {$this->columns} FROM {$this->table}";

        if (!empty($this->joins)) {
            $sql .= " " . implode(' ', $this->joins);
        }

        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' ', $this->wheres);
        }

        if (!empty($this->groupBy)) {
            $sql .= " GROUP BY " . implode(', ', $this->groupBy);
        }

        if (!empty($this->having)) {
            $sql .= " HAVING " . implode(' AND ', $this->having);
        }

        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY " . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= ($this->driver == 'sqlsrv') ? " SELECT TOP {$this->limit}" : " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    /**
     * Reset Query
     * @return void
     */
    protected function reset(): void
    {
        $this->columns  =   '*';
        $this->joins    =   [];
        $this->wheres   =   [];
        $this->bindings =   [];
        $this->groupBy  =   [];
        $this->orderBy  =   [];
        $this->limit    =   null;
        $this->offset   =   null;
        $this->having   =   [];
        $this->softDelete = false;
    }

    /**
     * Add Table Sanitization in Model Class
     */
    protected function sanitize(string $identifier): string
    {
        // Remove dangerous characters
        return preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
    }

    private function wrapIdent(string $name, string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => '`' . str_replace('`', '``', $name) . '`',
            'sqlsrv'           => '[' . str_replace(']', ']]', $name) . ']',
            default            => '"' . str_replace('"', '""', $name) . '"',
        };
    }

    /**
     * Validate and quote a column or table identifier for the current driver.
     *
     * Validation rule:  /^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/
     *   Allows: letters, digits, underscores, one optional dot (schema.table).
     *   Rejects: spaces, quotes, dashes, SQL keywords with special chars, etc.
     *
     * Quoting per driver:
     *   MySQL / MariaDB  →  `name`
     *   SQL Server       →  [name]
     *   All others       →  "name"  (ANSI SQL standard)
     *
     * @throws ModelException if the identifier fails validation.
     */
    public function quoteIdentifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/', $name)) {
            throw new ModelException(
                "Invalid identifier [{$name}]. Only letters, digits and underscores are allowed."
            );
        }

        if (str_contains($name, '.')) {
            [$a, $b] = explode('.', $name, 2);
            return $this->wrapIdent($a, $this->driver) . '.' . $this->wrapIdent($b, $this->driver);
        }

        return $this->wrapIdent($name, $this->driver);
    }

    /**
     * Prevent Cloning
     * @throws \Exception Throws an exception if cloning is attempted
     */
    private function __clone()
    {
        throw new \Exception('Cloning is Not Allowed.');
    }

    /**
     * Prevent Serialization
     * @throws \Exception Throws an exception if serialization is attempted
     */
    public function __wakeup()
    {
        throw new \Exception('Unserializing is Not Allowed.');
    }

    /**
     * Check if Property is Set
     * @param string $prop Property Name
     * @return bool
     */
    public function __isset($prop): bool
    {
        return isset($this->$prop);
    }

    /**
     * Get Property Value
     * @param string $prop Property Name
     * @return mixed
     */
    public function __get($prop): mixed
    {
        return $this->$prop;
    }
}
