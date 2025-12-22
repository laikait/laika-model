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
use Exception;
use Throwable;
use PDOException;
use InvalidArgumentException;
use Laika\Model\Compile\Quote;

class Model
{
    /**
     * @var PDO $pdo PDO Database Connection Object
     */
    protected PDO $pdo;

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

    protected string $connection = '';
    /**
     * @var string $table Table Name
     */

    public string $table;

    /**
     * @var string $uuid UUID Column Name
     */
    public string $id;

    /**
     * @var string $uuid UUID Column Name
     */
    public string $uuid;

    ####################################################################
    /*------------------------- EXTERNAL API -------------------------*/
    ####################################################################

    public function __construct(string $connection = 'default')
    {
        // Check Required Columns
        $this->checkRequiredProperties();
        $this->connection = $connection;
        $this->pdo = Connection::get($this->connection);
        $this->driver = Connection::driver($this->connection);
    }

    /**
     * Get PDO Object
     * @return PDO
     */
    public function pdo(): PDO
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
        if (!$columns) {
            return $this;
        }
        if (trim($columns) == '*') {
            return $this;
        }
        // Add Backtick
        $array = explode(',', $columns);
        // Trim & Quote Columns
        $trimed = array_map(function($v) {
            $v = trim($v);
            return call_user_func([new Quote($v, $this->driver), 'sql']);
        }, $array);
        $this->columns = implode(',', $trimed);
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
        $table = call_user_func([new Quote($table, $this->driver), 'sql']);
        $first = call_user_func([new Quote($first, $this->driver), 'sql']);
        $second = call_user_func([new Quote($second, $this->driver), 'sql']);

        if (!in_array($type, ['LEFT', 'RIGHT', 'INNER'])) {
            throw new InvalidArgumentException("Invalid join type: {$type}");
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
            $col = call_user_func([new Quote($col, $this->driver), 'sql']);
            $this->addWhere("{$col} {$operator} ?", [$val], $compare);
        }
        return $this;
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
        $column = call_user_func([new Quote($column, $this->driver), 'sql']);

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->addWhere("{$column} IN ({$placeholders})", $values, $compare);
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
        $column = call_user_func([new Quote($column, $this->driver), 'sql']);

        $this->addWhere("{$column} IS NULL", [], $compare);
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
        $column = call_user_func([new Quote($column, $this->driver), 'sql']);

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
            return call_user_func([new Quote($column, $this->driver), 'sql']);
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
        $column = call_user_func([new Quote($column, $this->driver), 'sql']);

        $this->having[]     =   "{$column} {$operator} ?";
        $this->bindings[]   =   $value;
        return $this;
    }

    /**
     * Order By Clause
     * @param string $column Required column name
     * @param string $direction Optional direction (ASC, DESC)
     * @return Model
     */
    public function order(string $column, string $direction = 'ASC'): Model
    {
        // Quote String
        $column = call_user_func([new Quote($column, $this->driver), 'sql']);

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
     * @param int|string $page Optional Argument. Default is Page Number 1
     * @return Model
     */
    public function offset(int|string $pageNumber = 1): Model
    {
        $offset = ((int)$pageNumber - 1) * (int) $this->limit;
        $this->offset = ($offset < 0) ? 0 : $offset;
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
        $result =   $this->get();
        $first  =   $result[0] ?? [];
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
     * Insert Row('s)
     * @param array{} $data Insert Row('s) Data. Example: ['name' => 'John', 'age' => 30] or [0 => ['name' => 'John'], ['name' => 'Doe']]
     * @return string|false Returns the last inserted ID
     */
    public function insert(array $data): string|false
    {
        if (empty($data)) {
            throw new InvalidArgumentException('Cannot Insert Empty Rows.');
        }

        // Normalize input: detect single row vs multiple rows
        $isMultiple = isset($data[0]) && is_array($data[0]);

        $rows = $isMultiple ? $data : [$data];

        // Extract columns from first row
        $keys = array_keys($rows[0]);

        // Quote columns
        $columns = array_map(function ($column) {
            return (new Quote($column, $this->driver))->sql();
        }, $keys);

        // Build placeholders
        $rowPlaceholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $placeholders = implode(', ', array_fill(0, count($rows), $rowPlaceholders));

        // Build SQL
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES {$placeholders}";

        // Log query
        Log::add($sql, $this->connection);

        // Flatten bindings
        $bindings = [];
        foreach ($rows as $row) {
            // Ensure row structure consistency
            if (array_keys($row) !== $keys) {
                throw new InvalidArgumentException('All insert rows must have identical columns.');
            }
            $bindings = array_merge($bindings, array_values($row));
        }

        // Execute
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($bindings);

        // Reset builder state
        $this->reset();

        return $result ? $this->pdo->lastInsertId() : false;
    }

    /**
     * Chunk the Results
     * @param int $size Chunk Size. Example: 100
     * @param callable $callback Callback function. Argument is an array of results. Example: function(array $rows) { ... }
     * @return void
     */
    public function chunk(int $size, callable $callback): void
    {
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
     * @return bool Returns true on success, false on failure
     */
    public function update(array $data): int
    {
        if (empty($this->wheres)) {
            throw new InvalidArgumentException("No WHERE Clause Provided for UPDATE operation.");
        }
        $set = [];
        foreach (array_keys($data) as $column) {
            $column = call_user_func([new Quote($column, $this->driver), 'sql']);
            $set[] = "{$column} = ?";
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $set);

        if (!empty($this->joins)) {
            $sql .= " " . implode(' ', $this->joins);
        }

        $sql .= " WHERE " . implode(' AND ', $this->wheres);

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
     * Delete Clause
     * @return int Returns the number of affected rows
     */
    public function delete(): int
    {
        $sql = "DELETE FROM {$this->table}";
        if (empty($this->wheres)) {
            throw new InvalidArgumentException("No WHERE Clause provided for DELETE operation.");
        }

        $sql .= " WHERE " . implode(' AND ', $this->wheres);

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
     * Get Meta
     * @return array{} Returns the results as an array
     */
    public function meta(): array
    {
        if (empty($this->table)) {
            throw new InvalidArgumentException("Table Name Doesn't Exists.");
        }
        $sql = "SELECT {$this->columns} FROM {$this->table} LIMIT 1";
        $stmt = $this->pdo->query($sql);
        $meta = [];
        $count = $stmt->columnCount();

        for ($i = 0; $i < $count; $i++) {
            $meta[] = $stmt->getColumnMeta($i);
        }

        $this->reset();
        return $meta;
    }

    /**
     * Get Columns
     * @return array{} Returns the results as an array
     */
    public function columns(): array
    {
        $meta = $this->meta();
        return array_map(function($v){
            return $v['name'];
        }, $meta);
    }

    /**
     * Run a Transactional Callback
     * @param callable $callback Callback Function. Use Model as Argument. Example: function(Model $model) { ... }
     * @return mixed Returns the result of the callback
     * @throws Throwable Rethrows any exception thrown within the callback
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // Generate UUID
    /**
     * @param ?string $column Optional Argument. Default is null
     * @return string
     */
    public function uuid(): string
    {
        $time = substr(str_replace('.', '', (string) microtime(true)), -6);
        $uid = 'uuid-'.bin2hex(random_bytes(3)).'-'.bin2hex(random_bytes(3)).'-'.bin2hex(random_bytes(3)).'-'.bin2hex(random_bytes(3)).'-'.$time;
        // Check Already Exist & Return
        if ($this->select($this->uuid)->where([$this->uuid => $uid])->first()) {
            return $this->uuid();
        }
        return $uid;
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
     * @return string Returns the built SQL query
     */
    private function build(): string
    {
        if (empty($this->table)) {
            throw new PDOException("Table Name Not Found!");
        }

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
            $sql .= " LIMIT {$this->limit}";
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
    }

    protected function checkRequiredProperties(): void
    {
        // Check Table Name
        if (empty($this->table)) {
            throw new InvalidArgumentException("Table Name Doesn't Exists!");
        }

        // Check ID Column
        if (empty($this->id)) {
            throw new InvalidArgumentException("[id] Column Name Not Defined in Model.");
        }

        // Check UUID Column
        if (empty($this->uuid)) {
            throw new InvalidArgumentException("[uuid] Column Name Not Defined in Model.");
        }
    }

    /**
     * Prevent Cloning
     * @throws Exception Throws an exception if cloning is attempted
     */
    private function __clone()
    {
        throw new Exception('Cloning is not allowed.');
    }

    /**
     * Prevent Serialization
     * @throws Exception Throws an exception if serialization is attempted
     */
    public function __wakeup()
    {
        throw new Exception('Unserializing is not allowed.');
    }
}