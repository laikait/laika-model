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
    /** @var \PDO PDO Database Connection Object*/
    protected \PDO $pdo;

    /** @var string Database Driver (mysql, sqlite, pgsql, sqlsrv, oci, firebird.) */
    protected string $driver;

    /** @var string Selected Columns */
    protected string $columns = '*';

    /** @var array Join Clauses */
    protected array $joins = [];

    /** @var array Where Clauses */
    protected array $wheres = [];

    /** @var array Query Bindings */
    protected array $bindings = [];

    /** @var array $groupBy Group By Clauses */
    protected array $groupBy = [];

    /** @var array $orderBy Order By Clauses */
    protected array $orderBy = [];

    /** @var ?int $limit Limit Clause */
    protected ?int $limit = null;

    /** @var array $having Having Clauses */
    protected array $having = [];

    /** @var string $connection Connection Name */
    protected string $connection;

    /** @var string $table Table Name */
    protected string $table;

    /** @var string $id ID Column Name */
    protected string $id = 'id';

    /** @var string $uid UID Column Name */
    protected string $uid = 'uid';

    /** @var bool $softDelete */
    protected bool $softDelete = false;

    /** @var string $deletedAtColumn */
    protected string $deletedAtColumn = 'deleted';

    /** @var array<string,string> Casts. Example: ['column1' => 'int', 'column2' => 'string', [.....]] */
    protected array $casts = [];

    /** @var ?int $page Page Number */
    protected ?int $page = null;

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
            return $this->sanitize($v);
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
        $allowedOps = ['=', '!=', '<>', '<', '>', '<=', '>='];
        if (!in_array(trim($operator), $allowedOps, true)) {
            throw new \InvalidArgumentException("Invalid join operator [{$operator}].");
        }

        $type = strtoupper($type);
        // Quote String
        $table = $this->sanitize($table);
        $first = $this->sanitize($first);
        $second = $this->sanitize($second);

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
        $allowed = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];
        if (!in_array(strtoupper(trim($operator)), $allowed, true)) {
            throw new \InvalidArgumentException("Invalid operator [{$operator}].");
        }

        foreach ($where as $col => $val) {
            // Quote String
            $col = $this->sanitize($col);
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
        $column = $this->sanitize($column);

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
        $column = $this->sanitize($column);
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
        $column = $this->sanitize($column);

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
        $column = $this->sanitize($column);

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
        $column = $this->sanitize($column);

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
            return $this->sanitize($column);
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
        $allowed = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];
        if (!in_array(strtoupper(trim($operator)), $allowed, true)) {
            throw new \InvalidArgumentException("Invalid operator [{$operator}].");
        }

        // Quote String
        $column = $this->sanitize($column);

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
        if (!in_array($direction, ['ASC', 'DESC'])) {
            throw new \InvalidArgumentException("Invalid order direction: {$direction}");
        }
        // Quote String
        $column = $this->sanitize($column);

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
    public function page(int|string $page = 1): Model
    {
        $this->page = max(1, (int) $page);
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

        // Fetch Rows
        $rows = $stmt->fetchAll();
        // Type Cast
        foreach ($rows as $k => $row) {
            $rows[$k] = $this->cast($row);
        }
        $this->reset();
        return $rows;
    }

    /**
     * Get First Result
     * @return array{} Returns the first result as an array
     */
    public function first(): array
    {
        // Check Where Clause Exists
        if (empty($this->wheres)) {
            throw new \InvalidArgumentException("WHERE Clause Required For Single Data.");
        }

        $this->limit(1);
        $result = $this->get();
        return $result[0] ?? [];
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
        try {
            $row = $this->first();
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
        }

        if (empty($row)) {
            throw new \RuntimeException("No Records Found");
        }
        return $row;
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
            return $this->sanitize($column);
        }, $keys);

        // Sanitize Table
        $tbl = $this->sanitize($this->table);

        // Chunk rows into max 1000 per query
        foreach (array_chunk($rows, 1000) as $chunk) {

            // Build placeholders for this chunk
            $rowPlaceholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $placeholders    = implode(', ', array_fill(0, count($chunk), $rowPlaceholders));

            // Build SQL
            $sql = "INSERT INTO {$tbl} (" . implode(', ', $columns) . ") VALUES {$placeholders}";

            // Log query
            Log::add($sql, $this->connection);

            // Flatten bindings
            $bindings = [];
            foreach ($chunk as $row) {
                // Ensure row structure consistency
                if (array_keys($row) !== $keys) {
                    throw new \InvalidArgumentException('All insert rows must have identical columns.');
                }
                $bindings = array_merge($bindings, array_values($row));
            }

            // Execute
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($bindings);
            } catch (\Throwable $th) {
                throw new \RuntimeException($th->getMessage());
            }
        }

        // Reset builder state
        $this->reset();
        return $this->pdo->lastInsertId();
    }

    /**
     * Chunk the Results
     * @param int $size Chunk Size. Example: 100
     * @param callable $callback Callback function. Argument is an array of results. Example: function(array $rows) { ... }
     * @return void
     */
    public function chunk(int $size, callable $callback): void
    {
        $size = max(1, $size);

        $page = 1;

        while (true) {
            $this->limit = $size;
            $this->page  = $page; // use page instead of offset directly

            $sql = $this->build();
            Log::add($sql, $this->connection);

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);

            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                $this->reset();
                break;
            }

            foreach ($rows as $k => $row) {
                $rows[$k] = $this->cast($row);
            }

            $callback($rows);
            $page++;
        }
    }

    /**
     * Update Clause
     * @param array $data Required data to update
     * @throws \InvalidArgumentException Throws an exception if no WHERE clause is provided for the update operation
     * @return int Returns the number of affected rows
     */
    public function update(array $data): int
    {
        // Check Where Clause Exists
        if (empty($this->wheres)) {
            throw new \InvalidArgumentException("No WHERE Clause Provided for UPDATE operation.");
        }

        $set = [];
        foreach (array_keys($data) as $column) {
            $column = $this->sanitize($column);
            $set[] = "{$column} = ?";
        }

        // Sanitize Table
        $tbl = $this->sanitize($this->table);

        // Make SQL
        $sql = "UPDATE {$tbl} SET " . implode(', ', $set);

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
        $tbl = $this->sanitize($this->table);

        // Make SQL
        $sql = "DELETE FROM {$tbl}";

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
     * Increment a numeric column by $number. Returns affected row count.
     * @param string $column Column to Increment
     * @param int $number Increment Number. default is 1
     * @example $users->increment('views', 1);
     */
    public function increment(string $column, int $number = 1): int
    {
        // Validate Column
        if (!preg_match('/^[a-z\._]+$/i', $column)) {
            throw new ModelException("Invalid Column [{$column}]");
        }

        if (str_contains($column, '.')) {
            [$tblName, $colName] = explode('.', $column, 2);
            $tbl = $this->sanitize($tblName);
            $col = $this->sanitize($colName);
        } else {
            $colName = $column;
            $tbl     = $this->sanitize($this->table);
            $col     = $this->sanitize($column);
        }

        if ($colName === $this->id) {
            throw new ModelException("Not Possible To Increment Primary Key!");
        }

        // Check Where Clause Exists
        if (empty($this->wheres)) {
            throw new \InvalidArgumentException("No WHERE Clause Provided For Increment Operation.");
        }

        $where = "WHERE " . implode(' ', $this->wheres);
        $sql = "UPDATE {$tbl} SET {$col} = {$col} + ? {$where}";

        // Add Queries to Log
        Log::add($sql, $this->connection);
    
        // Execute Query
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$number], $this->bindings));

        return $stmt->rowCount();
    }

    /**
     * Decrement a numeric column by $number. Returns affected row count.
     * @param string $column Column to Decrement
     * @param int $number Decrement Number. default is 1
     * @example $users->decrement('views', 1);
     */
    public function decrement(string $column, int $number = 1): int
    {
        // Validate Column
        if (!preg_match('/^[a-z\._]+$/i', $column)) {
            throw new ModelException("Invalid Column [{$column}]");
        }

        if (str_contains($column, '.')) {
            [$tblName, $colName] = explode('.', $column, 2);
            $tbl = $this->sanitize($tblName);
            $col = $this->sanitize($colName);
        } else {
            $colName = $column;
            $tbl     = $this->sanitize($this->table);
            $col     = $this->sanitize($column);
        }

        if ($colName === $this->id) {
            throw new ModelException("Not Possible To Increment Primary Key!");
        }

        // Check Where Clause Exists
        if (empty($this->wheres)) {
            throw new \InvalidArgumentException("No WHERE Clause Provided For Decrement Operation.");
        }

        $where = "WHERE " . implode(' ', $this->wheres);
        $sql = "UPDATE {$tbl} SET {$col} = {$col} - ? {$where}";

        // Add Queries to Log
        Log::add($sql, $this->connection);
    
        // Execute Query
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$number], $this->bindings));

        return $stmt->rowCount();
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
            throw new \RuntimeException("Table [{$this->table}] Transaction Failed [{$e->getMessage()}]", (int) $e->getCode(), $e);
        }
    }

    /**
     * Generate UID
     * @param string $prefix UID Prefix. Example: 'UID'
     * @param int $maxAttempts Maximum Try if UID Already Exists
     * @return string
     * @throws \RuntimeException
     */
    public function uid(int $maxAttempts = 10): string
    {        
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $time = substr(str_replace('.', '', (string) microtime(true)), -6);
            $str1 = bin2hex(random_bytes(3));
            $str2 = bin2hex(random_bytes(3));
            $str3 = bin2hex(random_bytes(3));
            $str4 = bin2hex(random_bytes(3));
            $uid = strtoupper("UID-{$str1}-{$str2}-{$str3}-{$str4}-{$time}");
            
            $exists = $this->select($this->uid)->where([$this->uid => $uid])->count() > 0;

            if (!$exists) {
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
        $tbl = $this->sanitize($this->table);

        $sql = "SELECT {$this->columns} FROM {$tbl}";

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

        $offset = null;

        if ($this->page !== null) {
            if ($this->limit === null) {
                throw new \InvalidArgumentException(
                    "Limit() Must Be Set Before Using Offset()."
                );
            }
            $offset = ($this->page - 1) * $this->limit;
        }

        if ($this->limit !== null) {
            switch ($this->driver) {
                case 'sqlsrv':
                    if ($offset !== null) {
                        if (empty($this->orderBy)) {
                            throw new \InvalidArgumentException(
                                "SQL Server Requires ORDER BY When Using OFFSET."
                            );
                        }
                        $sql .= " OFFSET {$offset} ROWS FETCH NEXT {$this->limit} ROWS ONLY";
                    } else {
                        $sql = preg_replace('/^SELECT\s/i', "SELECT TOP {$this->limit} ", $sql);
                    }
                    break;

                case 'oci':
                case 'oracle':
                    if ($offset !== null) {
                        if (empty($this->orderBy)) {
                            throw new \InvalidArgumentException(
                                "Oracle Requires ORDER BY When Using OFFSET."
                            );
                        }
                        $sql .= " OFFSET {$offset} ROWS FETCH NEXT {$this->limit} ROWS ONLY";
                    } else {
                        $sql .= " FETCH FIRST {$this->limit} ROWS ONLY";
                    }
                    break;

                case 'firebird':
                case 'ibase':
                    $start = ($offset ?? 0) + 1;
                    $end   = $start + $this->limit - 1;
                    $sql  .= " ROWS {$start} TO {$end}";
                    break;

                default:
                    $sql .= " LIMIT {$this->limit}";
                    if ($offset !== null) {
                        $sql .= " OFFSET {$offset}";
                    }
                    break;
            }
        }

        return $sql;
    }

    /**
     * Apply type casts to a fetched row.
     * @return array
     */
    protected function cast(array $row): array
    {
        foreach ($this->casts as $column => $type) {
            if (!array_key_exists($column, $row)) continue;

            $value = $row[$column];

            $row[$column] = match (strtolower($type)) {
                'int', 'integer'  => (int) $value,
                'float', 'double' => (float) $value,

                // Fix: compare against known falsy values instead of naive (bool) cast
                'bool', 'boolean' => !in_array($value, [0, '0', '', 'false', false, null], true),

                // Fix: guard against null and catch malformed JSON
                'array', 'json'   => $value === null
                                        ? null
                                        : (static function () use ($value) {
                                                $decoded = json_decode((string) $value, true);
                                                if (json_last_error() !== JSON_ERROR_NONE) {
                                                    throw new \UnexpectedValueException(
                                                        "Failed to decode JSON: " . json_last_error_msg()
                                                    );
                                                }
                                                return $decoded;
                                            })(),
                'serialize' => $value === null
                                    ? null
                                    : (static function () use ($value) {
                                        // unserialize returns false on failure — never suppress with @
                                        $result = unserialize((string) $value);
                                        if ($result === false && (string) $value !== 'b:0;') {
                                            throw new \UnexpectedValueException(
                                                "Failed to unserialize value: [{$value}]"
                                            );
                                        }
                                        return $result;
                                    })(),

                'string'          => $value === null ? null : (string) $value,
                default           => $value,
            };
        }
        return $row;
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
        $this->page     =   null;
        $this->having   =   [];
        $this->softDelete = false;
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
     * Add Table Sanitization in Model Class
     * @return string
     */
    protected function sanitize(string $identifier): string
    {
        // Remove dangerous characters
        // return preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);

        // Handle table.column notation
        if (str_contains($identifier, '.')) {
            [$table, $column] = explode('.', $identifier, 2);
            return $this->wrapIdent($this->validate($table), $this->driver)
                . '.'
                . $this->wrapIdent($this->validate($column), $this->driver);
        }

        return $this->wrapIdent($this->validate($identifier), $this->driver);
    }

    private function validate(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new ModelException("Invalid identifier [{$name}].");
        }
        return $name;
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
