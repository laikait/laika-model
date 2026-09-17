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

use Laika\Model\Schema\Expression;
use Laika\Model\Exceptions\ModelException;
use Laika\Model\Exceptions\ConnectionException;

class Model
{
    /**
     * @var \PDO PDO Database Connection Object.
     * Do not read directly — use pdo(), which refreshes a stale handle.
     */
    protected \PDO $pdo;

    /** @var string Canonical database driver (mysql, sqlite, pgsql, sqlsrv, oci, firebird). */
    protected string $driver;

    /** @var int Connection registry generation this instance's $pdo came from */
    private int $generation = -1;

    /** @var string Selected Columns */
    protected string $columns = '*';

    /** @var array Join Clauses */
    protected array $joins = [];

    /** @var string[] Tables joined into the current query, unquoted, for cache invalidation */
    protected array $joinTables = [];

    /** @var bool Whether remember() was called for the current query */
    private bool $remember = false;

    /** @var ?int TTL passed to remember(); null uses the configured default */
    private ?int $rememberTtl = null;

    /**
     * @var ?callable Returns the store query results are cached in, or null.
     * A resolver rather than the store itself, so nothing is built or connected
     * until a query actually asks to be remembered.
     */
    private static $queryCache = null;

    /** @var int Seconds a remembered result is kept when remember() is given none */
    private static int $queryCacheTtl = 60;

    /** @var ?object Returned by the store only on a miss; compared by identity */
    private static ?object $miss = null;

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

    /**
     * @var array HAVING bindings, kept apart from $bindings.
     *
     * build() emits HAVING after WHERE, so mixing the two lists desynchronised
     * the placeholders whenever having() was called before where().
     */
    protected array $havingBindings = [];

    /** @var string $connection Database Connection Name */
    protected string $connection = 'default';

    /** @var string $table Table Name */
    protected string $table;

    /** @var string $id ID Column Name */
    protected string $id = 'id';

    /** @var string $uid UID Column Name */
    protected string $uid = 'uid';

    /** @var bool $softDelete Whether this model soft-deletes and hides trashed rows. */
    protected bool $softDelete = false;

    /**
     * @var bool The value $softDelete was declared with.
     *
     * $softDelete doubles as per-chain state (soft()), so reset() has to put
     * back what the subclass declared rather than a hardcoded false — otherwise
     * a soft-delete model starts hard-deleting on its second query.
     */
    private bool $softDeleteDefault = false;

    /** @var bool Include soft-deleted rows in reads (withTrash()). */
    protected bool $withTrashed = false;

    /** @var bool Return only soft-deleted rows (onlyTrashed()). */
    protected bool $onlyTrashed = false;

    /** @var string $deletedAtColumn */
    protected string $deletedAtColumn = 'deleted_at';

    /** @var array<string,string> Casts. Example: ['column1' => 'int', 'column2' => 'string', [.....]] */
    protected array $casts = [];

    /** @var ?int $page Page Number */
    protected ?int $page = null;

    ####################################################################
    /*------------------------- EXTERNAL API -------------------------*/
    ####################################################################

    public function __construct(?string $connection = null)
    {
        // Set Connection Name. With no explicit choice here and none declared
        // on the subclass, honour Connection::setDefault().
        if (!empty($connection)) {
            $this->connection = $connection;
        } elseif ($this->connection === 'default') {
            $this->connection = Connection::getDefault();
        }

        // Init DB for Connection
        // if (class_exists("\\Laika\\Service\\Init")) \Laika\Service\Init::db($this->connection);

        // Remember what the subclass declared so reset() can restore it.
        $this->softDeleteDefault = $this->softDelete;

        $this->refreshConnection();
    }

    /**
     * Get PDO Object
     *
     * Re-resolves from the Connection registry when the registry has changed
     * since this instance was built (Connection::add()/close()/reconnect()
     * invalidate live handles), so a Model never runs against a dead socket.
     *
     * @return \PDO
     */
    public function pdo(): \PDO
    {
        if ($this->generation !== Connection::generation()) {
            $this->refreshConnection();
        }

        return $this->pdo;
    }

    /**
     * Get the canonical driver name for this model's connection.
     */
    public function driver(): string
    {
        if ($this->generation !== Connection::generation()) {
            $this->refreshConnection();
        }

        return $this->driver;
    }

    /**
     * Pull a fresh PDO handle and driver name from the registry.
     */
    private function refreshConnection(): void
    {
        // Add Connection if doesn't exists
        if (!Connection::has($this->connection)) {
            $config = config('database', $this->connection);
            if ($config === null) {
                throw new ConnectionException("Connection [{$this->connection}] is not configured!");
            }
            // Register under this model's name. Without it the config lands on
            // the default name, overwriting 'default', and get() below throws.
            Connection::add($config, $this->connection);
        }

        $this->pdo        = Connection::get($this->connection);
        $this->driver     = Connection::driver($this->connection);
        $this->generation = Connection::generation();
    }

    /**
     * // Table Name
     * @param string $table Required table name
     * @return Static
     */
    public function table(string $table): Static
    {
        // Deliberately does not reset(): calling table() mid-chain used to
        // discard the select and wheres already set, silently.
        $this->table = $table;
        return $this;
    }

    /**
     * Select
     * @param array|string|null $columns Column names. Default is null
     * @return Static
     */
    public function select(array|string|Expression|null $columns = null): Static
    {
        if ($columns === null || $columns === '' || $columns === []) {
            $this->columns = '*';
            return $this;
        }

        // A plain string may be a comma-separated list — "id, name, email".
        // Splitting is safe here because every part is sanitised below; the old
        // code split too, but then passed anything containing "(" through raw.
        $list = match (true) {
            is_array($columns)              => $columns,
            $columns instanceof Expression  => [$columns],
            default                         => explode(',', $columns),
        };

        $parts = array_map(function (mixed $col): string {
            // Raw SQL is opt-in and explicit. Anything else is an identifier.
            if ($col instanceof Expression) {
                return (string) $col;
            }

            if (!is_string($col)) {
                throw new ModelException('Select columns must be strings or Expression instances.');
            }

            $col = trim($col);

            // "expr AS alias" — both halves are identifiers here; use an
            // Expression for the left side when it needs to be a function call.
            if (preg_match('/^(.+?)\s+AS\s+(\S+)$/i', $col, $m)) {
                return $this->sanitize(trim($m[1])) . ' AS ' . $this->sanitize(trim($m[2]));
            }

            // "*" and "users.*" are wildcards, not identifiers.
            if ($col === '*') {
                return '*';
            }

            if (str_ends_with($col, '.*')) {
                return $this->sanitize(substr($col, 0, -2)) . '.*';
            }

            return $this->sanitize($col);
        }, $list);

        $parts = array_values(array_filter($parts, static fn (string $c): bool => $c !== ''));

        $this->columns = $parts === [] ? '*' : implode(', ', $parts);
        return $this;
    }

    /**
     * Select Distinct Rows
     * @return Static
     */
    public function distinct(): Static
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
     * @return Static
     */
    public function join(string $table, string $first, string $operator, string $second, string $type = 'LEFT'): Static
    {
        $allowedOps = ['=', '!=', '<>', '<', '>', '<=', '>='];
        if (!in_array(trim($operator), $allowedOps, true)) {
            throw new ModelException("Invalid join operator [{$operator}].");
        }

        $type = strtoupper($type);
        // Kept unquoted: a write to this table has to invalidate this query
        $this->joinTables[] = $table;
        // Quote String
        $table = $this->sanitize($table);
        $first = $this->sanitize($first);
        $second = $this->sanitize($second);

        if (!in_array($type, ['LEFT', 'RIGHT', 'INNER'])) {
            throw new ModelException("Invalid join type: {$type}");
        }

        $this->joins[] = "{$type} JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Where Clause
     * @param array|string $where Required column name or array of column-value pairs
     * @param string $operator Optional operator (default: '=')
     * @param string $compare Optional comparison type (AND, OR)
     * @return Static
     */
    public function where(array $where, string $operator = '=', string $compare = 'AND'): Static
    {
        $allowed = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];
        if (!in_array(strtoupper(trim($operator)), $allowed, true)) {
            throw new ModelException("Invalid operator [{$operator}].");
        }

        if (empty($where)) {
            return $this;
        }

        $operator = strtoupper(trim($operator));

        $parts    = [];
        $bindings = [];

        foreach ($where as $col => $val) {
            $parts[]    = $this->sanitize((string) $col) . " {$operator} ?";
            $bindings[] = $val;
        }

        // One where() call is one group: the columns inside it are joined by
        // $compare, and the group as a whole attaches to the chain with the same
        // $compare. Without the parentheses a multi-column OR leaked into the
        // surrounding chain and SQL's AND-binds-tighter rule silently returned
        // the wrong rows.
        $glue      = strtoupper(trim($compare)) === 'OR' ? ' OR ' : ' AND ';
        $condition = count($parts) === 1 ? $parts[0] : '(' . implode($glue, $parts) . ')';

        $this->addWhere($condition, $bindings, $compare);
        return $this;
    }

    /**
     * Where Not Equal
     * @param array|string $where Required column name or array of column-value pairs
     * @param string $compare Optional comparison type (AND, OR)
     * @return Static
     */
    public function whereNot(array $where, string $compare = 'AND'): Static
    {
        return $this->where($where, '!=', $compare);
    }

    /**
     * Where In
     * @param string $column Required column name
     * @param array $values Required array of values to match
     * @param string $compare Optional comparison type (AND, OR)
     * @return Static
     */
    public function whereIn(string $column, array $values, string $compare = 'AND'): Static
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
     * @return Static
     */
    public function whereNotIn(string $column, array $values, string $compare = 'AND'): Static
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
     * @return Static
     */
    public function isNull(string $column, string $compare = 'AND'): Static
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
     * @return Static
     */
    public function notNull(string $column, string $compare = 'AND'): Static
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
     * @return Static
     */
    public function between(string $column, mixed $value1, mixed $value2, string $compare = 'AND'): Static
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
     * @return Static
     */
    public function whereGroup(callable $callback, string $compare = 'AND'): Static
    {
        $model = new Static($this->connection);

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
     * @return Static
     */
    public function groupBy(string ...$columns): Static
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
     * @return Static
     */
    public function having(string $column, string $operator, mixed $value): Static
    {
        $allowed = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];
        if (!in_array(strtoupper(trim($operator)), $allowed, true)) {
            throw new ModelException("Invalid operator [{$operator}].");
        }

        // Quote String
        $column = $this->sanitize($column);

        $this->having[]         = "{$column} " . strtoupper(trim($operator)) . " ?";
        $this->havingBindings[] = $value;
        return $this;
    }

    /**
     * Order By Clause
     * @param string $column Required column name
     * @param string $direction Optional direction (ASC, DESC)
     * @throws \InvalidArgumentException Throws an exception if an invalid direction is provided
     * @return Static
     */
    public function order(string $column, string $direction = 'ASC'): Static
    {
        $direction = strtoupper($direction);
        // Check Direction
        if (!in_array($direction, ['ASC', 'DESC'])) {
            throw new ModelException("Invalid order direction: {$direction}");
        }
        // Quote String
        $column = $this->sanitize($column);

        $this->orderBy[] = "{$column} {$direction}";
        return $this;
    }

    /**
     * Limit Clause
     * @param int|string $limit Required limit
     * @return Static
     */
    public function limit(int|string $limit): Static
    {
        $this->limit = (int) $limit;
        return $this;
    }

    /**
     * Offset Clause
     * @param int|string $page Page Number. Default is Page Number 1
     * @return Static
     */
    public function page(int|string $page = 1): Static
    {
        $this->page = max(1, (int) $page);
        return $this;
    }

    /**
     * Include soft-deleted rows in the result.
     *
     * NOTE: this used to return *only* trashed rows, the opposite of the name.
     * onlyTrashed() is that behaviour.
     *
     * @return Static
     */
    public function withTrash(): Static
    {
        $this->withTrashed = true;
        $this->onlyTrashed = false;
        return $this;
    }

    /**
     * Return only soft-deleted rows.
     * @return Static
     */
    public function onlyTrashed(): Static
    {
        $this->onlyTrashed = true;
        $this->withTrashed = false;
        return $this;
    }

    /**
     * Exclude soft-deleted rows. This is already the default on a soft-delete
     * model; it is here for models that opt in per chain with soft().
     * @return Static
     */
    public function withoutTrash(): Static
    {
        $this->withTrashed = false;
        $this->onlyTrashed = false;
        return $this;
    }

    /**
     * Enable Soft Delete
     * @param bool $enable Default is true
     * @return Static
     */
    public function soft(bool $enable = true): Static
    {
        $this->softDelete = $enable;
        return $this;
    }

    /**
     * Get Result
     *
     * Rows come back as arrays or stdClass depending on the connection's
     * PDO::ATTR_DEFAULT_FETCH_MODE; both are supported.
     *
     * @return array<int,array|object>
     */
    public function get(): array
    {
        $sql = $this->build();

        try {
            // The key is taken after build(), which folds the HAVING bindings in
            // and appends the soft-delete predicate: taken earlier it would miss
            // both and two different queries could share one entry.
            $key = $this->queryCacheKey('get', $sql);

            if ($key !== null) {
                $cached = $this->queryCacheRead($key);

                if (is_array($cached)) {
                    // Raw rows are cached, not cast ones, so the model's casts
                    // apply on a hit exactly as on a miss
                    return array_map(fn (array $row) => $this->cast($cached['objects'] ? (object) $row : $row), $cached['rows']);
                }
            }

            // Logged only when the database is actually asked
            Log::add($sql, $this->connection);

            $stmt = $this->run($sql, $this->bindings);

            $rows = $stmt->fetchAll();

            if ($key !== null) {
                // Stored as arrays: under FETCH_OBJ a row is a stdClass, which
                // the cache deliberately refuses to rebuild from serialized data
                $this->queryCacheWrite($key, [
                    'objects' => isset($rows[0]) && is_object($rows[0]),
                    'rows'    => array_map(static fn ($row) => (array) $row, $rows),
                ]);
            }

            foreach ($rows as $k => $row) {
                $rows[$k] = $this->cast($row);
            }

            return $rows;
        } finally {
            // Without this, any failure above left the wheres and bindings
            // attached to the instance and they leaked into the next query.
            $this->reset();
        }
    }

    /**
     * Stream the result one row at a time.
     *
     * Use this instead of get() for result sets too large to materialise. The
     * finally runs on GeneratorExit too, so abandoning the loop early still
     * resets the builder.
     *
     * @return \Generator<int,array|object>
     */
    public function cursor(): \Generator
    {
        $sql = $this->build();
        Log::add($sql, $this->connection);

        $bindings = $this->bindings;
        $stmt     = null;

        try {
            $stmt = $this->run($sql, $bindings);

            while (($row = $stmt->fetch()) !== false) {
                yield $this->cast($row);
            }
        } finally {
            $stmt?->closeCursor();
            $this->reset();
        }
    }

    /**
     * Get First Result
     * @return array|object|null The row, or null when nothing matched.
     */
    public function first(): array|object|null
    {
        if (empty($this->wheres)) {
            throw new ModelException("WHERE Clause Required For Single Data.");
        }

        $this->limit(1);
        $result = $this->get();

        return $result[0] ?? null;
    }

    /**
     * Find By ID / UID
     * @param int|string $id ID / UID
     * @return array|object|null The row, or null when nothing matched.
     */
    public function find(int|string $id): array|object|null
    {
        return is_numeric($id)
            ? $this->where([$this->id => (int) $id])->first()
            : $this->where([$this->uid => (string) $id])->first();
    }

    /**
     * Count Rows
     *
     * COUNT is built independently of select() — deriving it from $columns made
     * count() a syntax error after select([...]) or distinct().
     *
     * @return int
     */
    public function count(): int
    {
        $distinct = str_starts_with($this->columns, 'DISTINCT ');

        if ($distinct) {
            $expr = trim(substr($this->columns, strlen('DISTINCT ')));
            $this->columns = $expr === '*' ? 'COUNT(*)' : "COUNT(DISTINCT {$expr})";
        } else {
            $this->columns = 'COUNT(*)';
        }

        $this->columns .= ' AS ' . $this->sanitize('aggregate');

        // An aggregate over a page is not a count of the table.
        $this->limit = null;
        $this->page  = null;

        $sql = $this->build();

        try {
            $key = $this->queryCacheKey('count', $sql);

            if ($key !== null) {
                $cached = $this->queryCacheRead($key);

                if (is_int($cached)) {
                    return $cached;
                }
            }

            Log::add($sql, $this->connection);

            $stmt   = $this->run($sql, $this->bindings);
            $result = $stmt->fetch();
            $count  = $result === false ? 0 : (int) $this->rowGet($result, 'aggregate');

            if ($key !== null) {
                $this->queryCacheWrite($key, $count);
            }

            return $count;
        } finally {
            $this->reset();
        }
    }

    /**
     * Get Single Column Values as Array
     *
     * The result key is the alias or the bare column, never the qualified name,
     * so pluck('users.email') reads back as 'email'.
     *
     * @param string $column Column Name
     * @return array
     */
    public function pluck(string $column): array
    {
        $key = $column;

        if (preg_match('/\s+AS\s+(\S+)$/i', $column, $m)) {
            $key = $m[1];
        } elseif (str_contains($column, '.')) {
            $key = substr($column, strrpos($column, '.') + 1);
        }

        $rows = $this->select($column)->get();

        return array_map(fn (array|object $row): mixed => $this->rowGet($row, $key), $rows);
    }

    /**
     * Check if records exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * First Or Create
     *
     * NOTE: this is three statements without a transaction, so two concurrent
     * callers can both miss and both insert. Wrap it in transaction() when that
     * matters.
     *
     * @param array $where Columns to search for existing record
     * @param array $data Additional data to insert if not found
     * @return array|object|null Found or newly created record
     */
    public function firstOrCreate(array $where, array $data = []): array|object|null
    {
        $row = $this->where($where)->first();

        if ($row !== null) {
            return $row;
        }

        $this->insert(array_merge($where, $data));

        return $this->where($where)->first();
    }

    /**
     * Get First or Fail
     * @throws ModelException When no record matches.
     * @return array|object
     */
    public function firstOrFail(): array|object
    {
        $row = $this->first();

        if ($row === null) {
            throw new ModelException("No Records Found");
        }

        return $row;
    }

    /**
     * Insert Row('s)
     *
     * Oracle and Firebird reject multi-row VALUES, so on those drivers the rows
     * are sent one statement at a time instead of batched. They also have no
     * bare lastInsertId() — this returns '' there, and the caller must read the
     * sequence/generator itself.
     *
     * The returned id is the last row's on pgsql, which reads it back through
     * RETURNING, and the first of the final statement on mysql, which reports
     * LAST_INSERT_ID(). Either is '' when the driver cannot name one.
     *
     * @param array{} $data Insert Row('s) Data. Example: ['name' => 'John', 'age' => 30] or [0 => ['name' => 'John'], ['name' => 'Doe']]
     * @throws ModelException|\PDOException
     * @return string|false Returns the last inserted ID
     */
    public function insert(array $data): string|false
    {
        if (empty($data)) {
            throw new ModelException('Cannot Insert Empty Rows.');
        }

        // Normalize input: detect single row vs multiple rows. array_is_list()
        // rather than isset($data[0]) — rows out of array_filter() are keyed
        // 1,2,3 and used to be misread as a single row.
        $isMultiple = array_is_list($data) && isset($data[0]) && is_array($data[0]);

        $rows = $isMultiple ? array_values($data) : [$data];

        // Extract columns from first row
        $keys = array_keys($rows[0]);

        // Validate every row before executing anything. This used to happen
        // inside the chunk loop, so a bad row at index 1500 left the first
        // chunk already committed.
        foreach ($rows as $i => $row) {
            if (array_keys($row) !== $keys) {
                throw new ModelException(
                    "All Insert Rows Must Have Identical Columns (row {$i} differs)."
                );
            }
        }

        // Quote columns
        $columns = array_map(function ($column) {
            return $this->sanitize($column);
        }, $keys);

        // Sanitize Table
        $tbl = $this->sanitize($this->table);

        $driver = $this->driver();

        // Oracle needs INSERT ALL and Firebird needs EXECUTE BLOCK to batch;
        // rather than carry two more dialects, send those one row per statement.
        //
        // Everywhere else the real ceiling is the driver's placeholder limit and
        // not a row count, so it has to be divided by the width of a row: pgsql
        // and mysql carry the parameter count of a prepared statement in an
        // int16, sqlite defaults to half that, and SQL Server stops at 2100. A
        // flat 1000 rows overflowed all four on a wide enough table.
        $maxPlaceholders = match ($driver) {
            'sqlsrv' => 2100,
            'sqlite' => 32766,
            default  => 65535,
        };

        $rowsPerStatement = in_array($driver, ['oci', 'firebird'], true)
            ? 1
            : max(1, min(1000, intdiv($maxPlaceholders, max(1, count($columns)))));

        $chunks = array_chunk($rows, $rowsPerStatement);

        // pdo_pgsql's bare lastInsertId() is SELECT lastval(), which is scoped to
        // the session rather than to this table: on a table with no sequence it
        // hands back an id from an unrelated earlier insert, and an AFTER INSERT
        // trigger that burns another sequence shadows the row's own value. Both
        // are silent. RETURNING asks the statement itself instead.
        $returning = $driver === 'pgsql' ? $this->sanitize($this->id) : null;

        try {
            try {
                $lastId = $this->runInsert($tbl, $columns, $chunks, $returning);
            } catch (\PDOException $e) {
                // 42703 undefined_column — the model's $id is not on this table
                // (a join or log table). RETURNING is checked before anything is
                // written, so the batch is intact: send it again without one.
                // runInsert has already rolled back, so pgsql is not left with
                // an aborted transaction here.
                if ($returning === null || (string) $e->getCode() !== '42703') {
                    throw $e;
                }

                $returning = null;
                $lastId    = $this->runInsert($tbl, $columns, $chunks, null);
            }
        } finally {
            $this->reset();
        }

        $this->invalidateQueryCache($this->table);

        // RETURNING answered it, so lastval() is not consulted at all.
        if ($lastId !== null) {
            return $lastId;
        }

        // PDO_OCI and PDO_Firebird require the sequence/generator name here and
        // return '' or throw without one. pgsql only reaches this line when the
        // table had no id column to return, and falling through to its bare
        // lastInsertId() would resurrect the very bug RETURNING fixes: lastval()
        // would hand back whatever sequence the session touched last. Don't
        // pretend to have an id, and never turn a successful write into an
        // exception.
        if (in_array($driver, ['oci', 'firebird', 'pgsql'], true)) {
            return '';
        }

        try {
            return $this->pdo()->lastInsertId();
        } catch (\PDOException) {
            return '';
        }
    }

    /**
     * Chunk the Results
     *
     * Pages with LIMIT/OFFSET, which is O(n^2) on large tables and can skip or
     * repeat rows if the callback mutates the set. Prefer cursor() unless you
     * specifically need batches.
     *
     * @param int $size Chunk Size. Example: 100
     * @param callable $callback Receives each batch. Return false to stop.
     * @return void
     */
    public function chunk(int $size, callable $callback): void
    {
        $size = max(1, $size);

        // A caller's limit() is a cap on the total, not per batch.
        $remaining = $this->limit;

        $wheres   = $this->wheres;
        $bindings = $this->bindings;
        $havingB  = $this->havingBindings;
        $page     = 1;

        try {
            while (true) {
                $take = $remaining === null ? $size : min($size, $remaining);

                if ($take <= 0) {
                    break;
                }

                // build() consumes the where state, so restore it each round.
                $this->wheres         = $wheres;
                $this->bindings       = $bindings;
                $this->havingBindings = $havingB;
                $this->limit          = $take;
                $this->page           = $page;

                $sql = $this->build();
                Log::add($sql, $this->connection);

                $stmt = $this->run($sql, $this->bindings);

                $rows = $stmt->fetchAll();
                $stmt->closeCursor();

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $k => $row) {
                    $rows[$k] = $this->cast($row);
                }

                if ($callback($rows) === false) {
                    break;
                }

                if ($remaining !== null) {
                    $remaining -= count($rows);
                }

                if (count($rows) < $take) {
                    break;
                }

                $page++;
            }
        } finally {
            $this->reset();
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
            throw new ModelException("No WHERE Clause Provided for UPDATE operation.");
        }

        // Check Data Is Not Empty
        if (empty($data)) {
            throw new ModelException("'\$data' Parameter Should Not Be Empty!");
        }

        $set = [];
        foreach (array_keys($data) as $column) {
            $column = $this->sanitize($column);
            $set[] = "{$column} = ?";
        }

        // Sanitize Table
        $tbl = $this->sanitize($this->table);

        // Make SQL. The join goes before SET — appending it after produced
        // "UPDATE t SET a = ? LEFT JOIN ...", a syntax error on every driver.
        $sql = "UPDATE {$tbl}";

        if (!empty($this->joins)) {
            $sql .= " " . implode(' ', $this->joins);
        }

        $sql .= " SET " . implode(', ', $set);

        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' ', $this->wheres);
        }

        // Add Queries to Log
        Log::add($sql, $this->connection);

        try {
            $stmt = $this->run($sql, array_merge(array_values($data), $this->bindings));
            $this->invalidateQueryCache($this->table);

            return $stmt->rowCount();
        } finally {
            $this->reset();
        }
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
            throw new ModelException("No WHERE Clause provided for DELETE operation.");
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

        try {
            $stmt = $this->run($sql, $this->bindings);
            $this->invalidateQueryCache($this->table);

            return $stmt->rowCount();
        } finally {
            $this->reset();
        }
    }

    /**
     * Increment a numeric column by $number. Returns affected row count.
     * @param string $column Column to Increment
     * @param int $number Increment Number. default is 1
     * @example $users->increment('views', 1);
     */
    public function increment(string $column, int $number = 1): int
    {
        return $this->step($column, $number, '+', 'Increment');
    }

    /**
     * Decrement a numeric column by $number. Returns affected row count.
     * @param string $column Column to Decrement
     * @param int $number Decrement Number. default is 1
     * @example $users->decrement('views', 1);
     */
    public function decrement(string $column, int $number = 1): int
    {
        return $this->step($column, $number, '-', 'Decrement');
    }

    /**
     * Shared body for increment()/decrement().
     *
     * Identifier validation goes through sanitize() like everywhere else. The
     * old private regex /^[a-z._]+$/i rejected any column with a digit, so
     * views2 and q1_total were unusable.
     */
    private function step(string $column, int $number, string $sign, string $label): int
    {
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
            throw new ModelException("Not Possible To {$label} Primary Key!");
        }

        if (empty($this->wheres)) {
            throw new ModelException("No WHERE Clause Provided For {$label} Operation.");
        }

        $where = "WHERE " . implode(' ', $this->wheres);
        $sql   = "UPDATE {$tbl} SET {$col} = {$col} {$sign} ? {$where}";

        Log::add($sql, $this->connection);

        try {
            $stmt = $this->run($sql, array_merge([$number], $this->bindings));
            $this->invalidateQueryCache(str_contains($column, '.') ? $tblName : $this->table);

            return $stmt->rowCount();
        } finally {
            $this->reset();
        }
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
            throw new ModelException("No WHERE Clause provided for Restore operation.");
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

        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($bindings);

            return $stmt;
        } finally {
            // This bypasses the builder entirely; anything already chained is
            // discarded rather than left dangling on the instance.
            $this->reset();
        }
    }

    /**
     * Debug SQL
     * @return string Returns the SQL query with bindings
     */
    public function debug(): string
    {
        // build() mutates: it folds the HAVING bindings into $bindings and adds
        // the soft-delete predicate. Since debug() no longer resets, that state
        // has to be put back or a following get() would apply both twice.
        $snapshot = [
            $this->wheres,
            $this->bindings,
            $this->havingBindings,
            $this->columns,
            $this->limit,
            $this->page,
        ];

        try {
            $sql      = $this->build();
            $bindings = $this->bindings;
        } finally {
            [
                $this->wheres,
                $this->bindings,
                $this->havingBindings,
                $this->columns,
                $this->limit,
                $this->page,
            ] = $snapshot;
        }

        $sql = preg_replace_callback('/\?/', function () use (&$bindings) {
            $value = array_shift($bindings);

            return match (true) {
                $value === null    => 'NULL',
                is_bool($value)    => $value ? 'TRUE' : 'FALSE',
                is_numeric($value) => (string) $value,
                is_array($value)   => "'" . addslashes(json_encode($value)) . "'",
                default            => "'" . addslashes((string) $value) . "'",
            };
        }, $sql);

        // Deliberately no reset() — inspecting a query used to destroy it, so
        // ->where(...)->debug() then ->get() ran without the where.
        return "{$sql};";
    }

    /**
     * Run a Transactional Callback
     *
     * Nested calls on the same connection are supported via savepoints: only
     * the outermost call opens a real transaction, and an inner failure rolls
     * back to its own savepoint rather than discarding the outer transaction.
     *
     * @param callable $callback Callback Function. Use Model as Argument. Example: function(Model $model) { ... }
     * @return mixed Returns the result of the callback
     * @throws \Throwable Whatever the callback threw, unwrapped.
     */
    public function transaction(callable $callback): mixed
    {
        // Make sure a stale handle is refreshed before the transaction opens —
        // reconnecting mid-transaction would silently discard it.
        $this->pdo();

        Connection::beginTransaction($this->connection);

        try {
            $result = $callback($this);
            Connection::commit($this->connection);
            return $result;
        } catch (\Throwable $e) {
            try {
                Connection::rollBack($this->connection);
            } catch (\Throwable $rollbackError) {
                // Connection already gone — the original failure is the one
                // worth reporting, so swallow this and fall through.
            }

            // Rethrow as-is. Wrapping destroyed PDOException::$errorInfo and the
            // SQLSTATE, and turned a domain exception thrown to abort the
            // transaction into an unrecognisable RuntimeException.
            throw $e;
        }
    }

    /**
     * Cache This Query's Result
     *
     * Opt-in per query, and a no-op unless a store was configured through
     * setQueryCache() -- the query then simply runs. get() and count() honour it;
     * first(), find() and pluck() go through get(). cursor() never caches: it
     * exists for result sets too large to hold, which is also too large to cache.
     *
     * A write through this model to the table, or to any table joined into the
     * query, invalidates it. A write the model cannot see -- execute() with raw
     * SQL, another application, a table reached only through a raw expression
     * or subquery -- does not; call forgetQueryCache() for those.
     *
     * @param ?int $ttl Seconds; null uses the configured default
     * @return static
     */
    public function remember(?int $ttl = null): static
    {
        $this->remember = true;
        $this->rememberTtl = $ttl;

        return $this;
    }

    /**
     * Configure Where Remembered Queries Are Cached
     *
     * Takes a resolver so nothing is built or connected at boot, only when a
     * query first asks. It must return an object with get(), set() and pop() --
     * a Laika\Cache\Contracts\CacheDriverInterface -- or null for no cache.
     * This package does not require laika-cache, so the type is not declared.
     *
     * @param ?callable $resolver Null disables query caching
     * @param int $ttl Default seconds for remember() without its own TTL
     * @return void
     */
    public static function setQueryCache(?callable $resolver, int $ttl = 60): void
    {
        self::$queryCache = $resolver;
        self::$queryCacheTtl = max(0, $ttl);
    }

    /**
     * Invalidate Every Cached Query on a Table
     *
     * For writes the model does not see. Takes effect after the current
     * transaction commits, like every other invalidation.
     *
     * @param string $table Unquoted table name
     * @param ?string $connection Default is 'default'
     * @return void
     */
    public static function forgetQueryCache(string $table, ?string $connection = null): void
    {
        $connection ??= 'default';
        $key = self::generationKey($connection, $table);

        Connection::afterCommit(static function () use ($key): void {
            self::cacheStore()?->pop($key);
        }, $connection);
    }

    /**
     * Generate UID
     * @return string
     */
    public function uid(): string
    {        
        return implode('-', str_split(bin2hex(random_bytes(16)), 8));
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
    /**
     * Add the soft-delete predicate to a read.
     *
     * Only applies to a soft-delete model. Previously $softDelete affected
     * delete() alone, so reads returned trashed rows by default.
     */
    private function applyTrashFilter(): void
    {
        if ($this->onlyTrashed) {
            $this->addWhere($this->sanitize($this->deletedAtColumn) . ' IS NOT NULL');
            return;
        }

        if ($this->softDelete && !$this->withTrashed) {
            $this->addWhere($this->sanitize($this->deletedAtColumn) . ' IS NULL');
        }
    }

    /**
     * Send the prepared chunks, optionally asking PostgreSQL for the id it
     * generated.
     *
     * The batch runs inside a transaction whenever a partial insert is possible
     * — more than one statement — and always when RETURNING is in play, so a
     * column name the table does not have can be rolled back and retried.
     * Connection::beginTransaction() opens a SAVEPOINT rather than a second
     * transaction when the caller already has one open, so this is safe to nest.
     *
     * @param string   $tbl       Already-quoted table name.
     * @param string[] $columns   Already-quoted column names.
     * @param array<int,array<int,array<string,mixed>>> $chunks Rows, batched.
     * @param ?string  $returning Already-quoted id column, or null for no RETURNING.
     * @throws \PDOException
     * @return ?string The id RETURNING produced, or null when it was not used.
     */
    private function runInsert(string $tbl, array $columns, array $chunks, ?string $returning): ?string
    {
        $stmt        = null;
        $preparedSql = null;
        $lastId      = null;
        $lastChunk   = array_key_last($chunks);

        $wrap = count($chunks) > 1 || $returning !== null;

        if ($wrap) {
            Connection::beginTransaction($this->connection);
        }

        try {
            foreach ($chunks as $index => $chunk) {

                // Build placeholders for this chunk
                $rowPlaceholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $placeholders    = implode(', ', array_fill(0, count($chunk), $rowPlaceholders));

                // Build SQL
                $sql = "INSERT INTO {$tbl} (" . implode(', ', $columns) . ") VALUES {$placeholders}";

                // Only the final statement carries RETURNING: the id reported is
                // the last row's, which is what lastval() used to hand back here.
                $wantsId = $returning !== null && $index === $lastChunk;

                if ($wantsId) {
                    $sql .= " RETURNING {$returning}";
                }

                // Add Queries to Log
                Log::add($sql, $this->connection);

                // Flatten bindings
                $bindings = [];
                foreach ($chunk as $row) {
                    $bindings = array_merge($bindings, array_values($row));
                }

                // Execute. Every full chunk produces identical SQL, so the handle is
                // reused rather than re-prepared — this matters most on oci/firebird,
                // where the chunk is a single row. The PDOException is left intact:
                // wrapping it discarded errorInfo and the SQLSTATE, so callers could
                // not tell a duplicate key from a dead connection.
                if ($sql !== $preparedSql) {
                    $stmt        = $this->pdo()->prepare($sql);
                    $preparedSql = $sql;
                }

                // bindAll(), not execute($bindings): the array form binds every
                // value as PARAM_STR, and (string) false is '', which pgsql
                // rejects outright for a BOOLEAN, INTEGER or DATE column.
                $this->bindAll($stmt, $bindings);

                $stmt->execute();

                if ($wantsId) {
                    $ids    = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
                    $lastId = $ids === [] ? null : (string) end($ids);
                }
            }

            if ($wrap) {
                Connection::commit($this->connection);
            }
        } catch (\Throwable $e) {
            if ($wrap) {
                try {
                    Connection::rollBack($this->connection);
                } catch (\Throwable) {
                    // The original failure is the one worth reporting.
                }
            }

            throw $e;
        }

        return $lastId;
    }

    /**
     * Bind a positional list onto a prepared statement with the PDO type each
     * PHP value actually implies.
     *
     * PDOStatement::execute($array) binds every value as PDO::PARAM_STR instead.
     * Column affinity usually hides that, but (string) false is '', which pgsql
     * and sqlsrv reject outright for a BOOLEAN/BIT, INTEGER or DATE column; and
     * a comparison with no column to convert against — HAVING against an
     * aggregate alias, say — compares an integer to a string and silently
     * matches nothing.
     */
    private function bindAll(\PDOStatement $stmt, array $bindings): void
    {
        $i = 0;
        foreach ($bindings as $value) {
            $type = match (true) {
                $value === null => \PDO::PARAM_NULL,
                is_bool($value) => \PDO::PARAM_BOOL,
                is_int($value)  => \PDO::PARAM_INT,
                default         => \PDO::PARAM_STR,
            };

            $stmt->bindValue(++$i, $value, $type);
        }
    }

    /**
     * Prepare, bind and execute.
     */
    private function run(string $sql, array $bindings): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);

        $this->bindAll($stmt, $bindings);

        $stmt->execute();

        return $stmt;
    }

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
            throw new ModelException("Table Name Not Found!");
        }

        // A soft-delete model hides trashed rows unless asked otherwise. This
        // has to happen before the WHERE is assembled below.
        $this->applyTrashFilter();

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

            // HAVING is emitted after WHERE, so its bindings belong after the
            // WHERE bindings regardless of the order the methods were called in.
            $this->bindings = array_merge($this->bindings, $this->havingBindings);
            $this->havingBindings = [];
        }

        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY " . implode(', ', $this->orderBy);
        }

        $offset = null;

        if ($this->page !== null) {
            if ($this->limit === null) {
                throw new ModelException(
                    "limit() Must Be Set Before Using page()."
                );
            }
            $offset = ($this->page - 1) * $this->limit;
        }

        if ($this->limit !== null) {
            switch ($this->driver()) {
                case 'sqlsrv':
                    if ($offset !== null) {
                        if (empty($this->orderBy)) {
                            throw new ModelException(
                                "SQL Server Requires ORDER BY When Using OFFSET."
                            );
                        }
                        $sql .= " OFFSET {$offset} ROWS FETCH NEXT {$this->limit} ROWS ONLY";
                    } else {
                        // T-SQL wants SELECT DISTINCT TOP n, not SELECT TOP n DISTINCT.
                        $sql = preg_replace(
                            '/^SELECT\s+(DISTINCT\s+)?/i',
                            "SELECT $1TOP {$this->limit} ",
                            $sql
                        );
                    }
                    break;

                case 'oci':
                    if ($offset !== null) {
                        if (empty($this->orderBy)) {
                            throw new ModelException(
                                "Oracle Requires ORDER BY When Using OFFSET."
                            );
                        }
                        $sql .= " OFFSET {$offset} ROWS FETCH NEXT {$this->limit} ROWS ONLY";
                    } else {
                        $sql .= " FETCH FIRST {$this->limit} ROWS ONLY";
                    }
                    break;

                case 'firebird':
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
     * @return array|object
     */
    protected function cast(array|object $row): array|object
    {
        foreach ($this->casts as $column => $type) {
            if (!$this->rowHas($row, $column)) continue;

            $value = $this->rowGet($row, $column);

            // NULL means "absent" and must survive the cast. int/float/bool
            // used to coerce it to 0/0.0/false, losing the distinction.
            if ($value === null) {
                continue;
            }

            $row = $this->rowSet($row, $column, match (strtolower($type)) {
                'int', 'integer'  => $this->castInt($value),
                'float', 'double' => (float) $value,

                // DECIMAL comes back as a string from every driver; routing it
                // through float would introduce binary rounding error.
                'decimal'         => (string) $value,

                // Compare against known falsy values instead of a naive (bool)
                // cast. 't'/'f' are what pdo_pgsql returns for a boolean.
                'bool', 'boolean' => !in_array(
                    is_string($value) ? strtolower($value) : $value,
                    [0, 0.0, '0', '', 'false', 'f', 'off', 'no', false],
                    true
                ),

                'array', 'json'   => (static function () use ($value) {
                        $decoded = json_decode((string) $value, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new ModelException(
                                "Failed to decode JSON: " . json_last_error_msg()
                            );
                        }
                        return $decoded;
                    })(),

                'serialize' => (static function () use ($value) {
                        // No object instantiation from database content.
                        $result = unserialize((string) $value, ['allowed_classes' => false]);
                        if ($result === false && (string) $value !== 'b:0;') {
                            throw new ModelException(
                                "Failed to unserialize value: [{$value}]"
                            );
                        }
                        return $result;
                    })(),

                'string'          => (string) $value,

                // Falling through silently made a typo like 'integar' invisible.
                default           => throw new ModelException(
                    "Unknown cast type [{$type}] for column [{$column}]."
                ),
            });
        }

        return $row;
    }

    /**
     * Cast to int without silently clamping.
     *
     * A BIGINT UNSIGNED past PHP_INT_MAX (a snowflake id, say) would come back
     * as PHP_INT_MAX. Keeping it as a string is lossless.
     */
    private function castInt(mixed $value): int|string
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $asInt = (int) $value;

            if ((string) $asInt !== ltrim($value, '+')) {
                return $value;
            }

            return $asInt;
        }

        return (int) $value;
    }

    /**
     * Cache Key For The Current Query, or Null When it Must Not be Cached
     *
     * Null inside a transaction: a read there can see rows that are not
     * committed yet, and caching those would hand them to every other request.
     *
     * @param string $kind get or count, so the two never share an entry
     * @param string $sql The built statement
     * @return ?string
     */
    private function queryCacheKey(string $kind, string $sql): ?string
    {
        if (!$this->remember || self::$queryCache === null) {
            return null;
        }

        if (Connection::transactionLevel($this->connection) > 0) {
            return null;
        }

        $store = self::cacheStore();

        if ($store === null) {
            return null;
        }

        $generations = [];

        foreach (array_unique(array_merge([$this->table], $this->joinTables)) as $table) {
            $generations[$table] = $this->generation($store, $table);
        }

        try {
            $fingerprint = serialize([$kind, $this->connection, $this->driver(), $sql, $this->bindings, $generations]);
        } catch (\Throwable) {
            // A binding that cannot be serialized cannot be part of a key
            return null;
        }

        return 'query:' . sha1($fingerprint);
    }

    /**
     * @param string $key
     * @return mixed The cached value, or the miss sentinel
     */
    private function queryCacheRead(string $key): mixed
    {
        self::$miss ??= new \stdClass();

        try {
            return self::cacheStore()?->get($key, self::$miss) ?? self::$miss;
        } catch (\Throwable) {
            // A cache that fails is a miss, never a failed query
            return self::$miss;
        }
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    private function queryCacheWrite(string $key, mixed $value): void
    {
        try {
            self::cacheStore()?->set($key, $value, $this->rememberTtl ?? self::$queryCacheTtl);
        } catch (\Throwable) {
            // Not caching is always a safe outcome
        }
    }

    /**
     * Invalidate a Table's Cached Queries Once The Write is Committed
     * @param string $table
     * @return void
     */
    private function invalidateQueryCache(string $table): void
    {
        // Nothing configured, nothing cached, nothing to do: writes stay free
        if (self::$queryCache === null) {
            return;
        }

        self::forgetQueryCache($table, $this->connection);
    }

    /**
     * A Table's Current Generation Token
     *
     * Invalidation deletes the token rather than counting it up. A counter that
     * expired, or that Memcached evicted, would restart and could line up with a
     * key some old entry was stored under. A token minted fresh whenever it is
     * missing never does: losing it can only cause a miss.
     *
     * @param object $store
     * @param string $table
     * @return string
     */
    private function generation(object $store, string $table): string
    {
        $key = self::generationKey($this->connection, $table);

        try {
            $token = $store->get($key);

            if (is_string($token) && $token !== '') {
                return $token;
            }

            $token = bin2hex(random_bytes(8));
            // No expiry: the token must outlive every entry keyed on it
            $store->set($key, $token, 0);

            return $token;
        } catch (\Throwable) {
            // Unique per call, so a broken store yields a miss, never a stale hit
            return bin2hex(random_bytes(8));
        }
    }

    /**
     * @param string $connection
     * @param string $table
     * @return string
     */
    private static function generationKey(string $connection, string $table): string
    {
        return 'query-gen:' . $connection . ':' . strtolower(trim($table));
    }

    /**
     * @return ?object
     */
    private static function cacheStore(): ?object
    {
        if (self::$queryCache === null) {
            return null;
        }

        try {
            $store = (self::$queryCache)();
        } catch (\Throwable) {
            return null;
        }

        return is_object($store) ? $store : null;
    }

    /**
     * Reset Query
     * @return void
     */
    protected function reset(): void
    {
        $this->columns  =   '*';
        $this->joins    =   [];
        $this->joinTables = [];
        $this->remember =   false;
        $this->rememberTtl = null;
        $this->wheres   =   [];
        $this->bindings =   [];
        $this->groupBy  =   [];
        $this->orderBy  =   [];
        $this->limit    =   null;
        $this->page     =   null;
        $this->having   =   [];
        $this->havingBindings = [];
        $this->softDelete = $this->softDeleteDefault;
        $this->withTrashed = false;
        $this->onlyTrashed = false;
    }

    /**
     * Read a column from a fetched row.
     *
     * Rows are arrays under PDO::FETCH_ASSOC and stdClass under FETCH_OBJ, and
     * callers may set either through the connection's `options`. These three
     * helpers are the only places that care which.
     */
    protected function rowGet(array|object $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);
    }

    protected function rowHas(array|object $row, string $key): bool
    {
        return is_array($row) ? array_key_exists($key, $row) : property_exists($row, $key);
    }

    protected function rowSet(array|object $row, string $key, mixed $value): array|object
    {
        if (is_array($row)) {
            $row[$key] = $value;
        } else {
            $row->{$key} = $value;
        }

        return $row;
    }

    private function wrapIdent(string $name, string $driver): string
    {
        return match ($driver) {
            'mysql'  => '`' . str_replace('`', '``', $name) . '`',
            'sqlsrv' => '[' . str_replace(']', ']]', $name) . ']',
            default  => '"' . str_replace('"', '""', $name) . '"',
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
        $driver = $this->driver();

        if (str_contains($identifier, '.')) {
            [$table, $column] = explode('.', $identifier, 2);
            return $this->wrapIdent($this->validate($table), $driver)
                . '.'
                . $this->wrapIdent($this->validate($column), $driver);
        }

        return $this->wrapIdent($this->validate($identifier), $driver);
    }

    private function validate(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new ModelException("Invalid Identifier [{$name}].");
        }
        return $name;
    }

    /**
     * Prevent Cloning
     * @throws \Exception Throws an exception if cloning is attempted
     */
    private function __clone()
    {
        throw new ModelException('Cloning is Not Allowed.');
    }

    /**
     * Prevent Serialization
     * @throws \Exception Throws an exception if serialization is attempted
     */
    public function __wakeup()
    {
        throw new ModelException('Unserializing is Not Allowed.');
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
