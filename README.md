# Laika Single/Multi Connecton Database Model
A lightweight, secure PDO database model and schema builder for PHP 8.1+.  
Supports MySQL, MariaDB, PostgreSQL, SQLite, SQL Server, Oracle, and Firebird.

**Author:** Showket Ahmed  
**License:** MIT

# Key Features
* <b>Object-Oriented Structure</b>: Built with PHP OOP principles, ensuring code reusability, scalability, and maintainability.</br>
* <b>Custom Database and Model Classes</b>: Uses a custom Database class for managing database connections, queries, and transactions, and a Model class to represent data entities in the application.</br>
* <b>Secure Transactions</b>: Implements ACID-compliant transactions for consistent and reliable data handling.</br>
* <b>Dynamic Query Builder</b>: Supports dynamic query generation with a range of options for filters, sorting, and pagination, making it easy to create complex queries without directly writing SQL.</br>
* <b>Error Handling</b>: Comprehensive error handling and logging for tracking and debugging issues efficiently.</br>
* <b>Scalable Architecture</b>: Designed with scalability in mind, suitable for all type of PHP applications.</br>
* <b>Easy Integration</b>: Integrates seamlessly with other PHP-based applications and frameworks, allowing flexible deployment in diverse environments.</br>

## Technologies Used
* <b>PHP (Object-Oriented)</b>: Core programming language, providing OOP features for structure and maintainability.</br>
* <b>MySQL</b>: Relational database management system used for data storage, with optimized queries for faster performance.</br>
* <b>PDO (PHP Data Objects)</b>: Utilized for secure database access with prepared statements to prevent SQL injection.</br>

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Connection Setup](#connection-setup)
- [Model](#model)
  - [Defining a Model](#defining-a-model)
  - [Select](#select)
  - [Where Clauses](#where-clauses)
  - [Ordering, Limiting, Pagination](#ordering-limiting-pagination)
  - [Joins](#joins)
  - [Aggregates](#aggregates)
  - [Insert](#insert)
  - [Update](#update)
  - [Delete](#delete)
  - [Soft Delete](#soft-delete)
  - [Increment & Decrement](#increment--decrement)
  - [Chunking](#chunking)
  - [Transactions](#transactions)
  - [Raw Queries](#raw-queries)
  - [Debugging](#debugging)
  - [UID Generation](#uid-generation)
  - [Type Casting](#type-casting)
- [Schema Builder](#schema-builder)
  - [Creating Tables](#creating-tables)
  - [Column Types](#column-types)
  - [Column Modifiers](#column-modifiers)
  - [Indexes & Constraints](#indexes--constraints)
  - [Foreign Keys](#foreign-keys)
  - [Modifying Tables](#modifying-tables)
  - [Dropping Tables](#dropping-tables)
  - [Inspecting Tables](#inspecting-tables)
  - [Raw Statements](#raw-statements)
  - [Multiple Connections](#multiple-connections)
  - [Custom Grammar](#custom-grammar)
- [Log](#log)
- [Driver Reference](#driver-reference)
- [Security](#security)

---

## Requirements

- PHP 8.1 or higher
- `ext-pdo` extension
- One of: `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `pdo_sqlsrv`, `pdo_oci`, `pdo_firebird`

---

## Installation

```bash
composer require laikait/laika-model
```

---

## Connection Setup

Register connections before using the Model or Schema. All connections are created lazily — no PDO object is created until it is first accessed.

```php
use Laika\Model\Connection;

// MySQL / MariaDB
Connection::add([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'myapp',
    'username' => 'root',
    'password' => 'secret',
    'charset'  => 'utf8mb4',
]);

// MySQL via Unix socket (localhost only)
Connection::add([
    'driver'      => 'mysql',
    'host'        => 'localhost',
    'unix_socket' => '/var/run/mysqld/mysqld.sock',
    'database'    => 'myapp',
    'username'    => 'root',
    'password'    => 'secret',
]);

// PostgreSQL
Connection::add([
    'driver'   => 'pgsql',
    'host'     => '127.0.0.1',
    'port'     => 5432,
    'database' => 'myapp',
    'username' => 'postgres',
    'password' => 'secret',
], 'pgsql');

// SQLite — file
Connection::add([
    'driver'   => 'sqlite',
    'database' => '/var/db/myapp.sqlite',
], 'sqlite');

// SQLite — in-memory (useful for testing)
Connection::add([
    'driver'   => 'sqlite',
    'database' => ':memory:',
], 'test');

// SQL Server
Connection::add([
    'driver'   => 'sqlsrv',
    'host'     => '127.0.0.1',
    'port'     => 1433,
    'database' => 'myapp',
    'username' => 'sa',
    'password' => 'secret',
], 'sqlsrv');

// Oracle
Connection::add([
    'driver'   => 'oci',
    'host'     => '127.0.0.1',
    'port'     => 1521,
    'database' => 'XE',
    'username' => 'system',
    'password' => 'secret',
], 'oracle');

// Firebird
Connection::add([
    'driver'   => 'firebird',
    'host'     => '127.0.0.1',
    'port'     => 3050,
    'database' => '/var/db/myapp.fdb',
    'username' => 'sysdba',
    'password' => 'masterkey',
], 'firebird');
```

### Connection Management

```php
Connection::has('read');          // check if connection is registered
Connection::names();              // ['default', 'read', ...]
Connection::close('read');        // destroy a live connection
Connection::closeAll();           // destroy all live connections
Connection::purge();              // remove all configs + connections (testing)
Connection::driver('default');    // get driver name: 'mysql', 'pgsql', etc.
```

---

## Model

### Defining a Model

Extend the base `Model` class and set your table name. All other properties are optional.

```php
use Laika\Model\Model;

class User extends Model
{
    /** @var string $table Table Name */
    protected string $table          = 'users';

    /** @var string $id Primary Column Name. [Optional] */
    protected string $id             = 'id';

    /** @var string $id Uid Column Name. [Optional] */
    protected string $uid            = 'uid';

    /** @var string $id Deleted At Column Column Name. [Optional] */
    protected string $deletedAtColumn = 'deleted_at';

    /**
     * Table Columns Name & Type Declaration
     * @var array{string:string} Example: ['column_1' => 'int', 'column_2' => 'string']
    */
    protected array $casts = [
        'id'      => 'int',
        'uid'     => 'string',
        'active'  => 'bool',
        'credits' => 'int',
        'meta'    => 'json',
    ];
}
```

Instantiate with an optional connection override:

```php
$users = new User();           // uses 'default' connection
$read  = new User('read');     // uses 'read' connection
```

---

### Select

```php
// All columns (default)
$users->get();

// Specific columns
$users->select('id, name, email')->get();

// All columns
$users->select('*')->get();

// Distinct rows
$users->select('role')->distinct()->get();
```

---

### Where Clauses

All column names are validated and quoted. All values are bound via prepared statements.

```php
// Equality (default operator)
$users->where(['active' => 1])->get();

// Custom operator
$users->where(['credits' => 100], '>')->get();

// Supported operators: =  !=  <>  <  >  <=  >=  LIKE  NOT LIKE
$users->where(['name' => '%alice%'], 'LIKE')->get();

// Not equal shorthand
$users->whereNot(['role' => 'banned'])->get();

// IN list
$users->whereIn('id', [1, 2, 3])->get();

// NOT IN list
$users->whereNotIn('role', ['banned', 'spam'])->get();

// IS NULL
$users->isNull('deleted_at')->get();

// IS NOT NULL
$users->notNull('email')->get();

// BETWEEN
$users->between('credits', 10, 100)->get();

// AND / OR combining
$users
    ->where(['active' => 1])
    ->where(['role' => 'admin'], '=', 'OR')
    ->get();

// Grouped conditions — (a AND b) OR (c AND d)
$users
    ->whereGroup(function (Model $m) {
        $m->where(['role' => 'admin'])->where(['active' => 1]);
    })
    ->whereGroup(function (Model $m) {
        $m->where(['role' => 'moderator'])->where(['active' => 1]);
    }, 'OR')
    ->get();
```

---

### Ordering, Limiting, Pagination

```php
// Order by single column
$users->order('created_at', 'DESC')->get();

// Order by multiple columns
$users
    ->order('role', 'ASC')
    ->order('created_at', 'DESC')
    ->get();

// Limit
$users->limit(10)->get();

// Pagination — page() takes a PAGE NUMBER, not a row offset
// Page 1 = rows 1–10, Page 2 = rows 11–20, etc.
$users->limit(10)->page(1)->get(); // page 1
$users->limit(10)->page(2)->get(); // page 2
$users->limit(10)->page(3)->get(); // page 3
```

---

### Joins

```php
// LEFT JOIN (default)
$users
    ->join('posts', 'users.id', '=', 'posts.user_id')
    ->select('users.name, posts.title')
    ->get();

// INNER JOIN
$users
    ->join('orders', 'users.id', '=', 'orders.user_id', 'INNER')
    ->get();

// RIGHT JOIN
$users
    ->join('profiles', 'users.id', '=', 'profiles.user_id', 'RIGHT')
    ->get();

// Multiple joins
$users
    ->join('posts', 'users.id', '=', 'posts.user_id')
    ->join('comments', 'posts.id', '=', 'comments.post_id', 'INNER')
    ->select('users.name, posts.title, comments.body')
    ->get();
```

---

### Aggregates

```php
// Count all rows
$total = $users->count();

// Count with condition
$active = $users->where(['active' => 1])->count();

// Check existence
$exists = $users->where(['email' => 'alice@example.com'])->exists();

// First matching row — requires WHERE clause
$user = $users->where(['id' => 1])->first();

// First or throw RuntimeException
$user = $users->where(['id' => 1])->firstOrFail();

// Single column from all matching rows
$emails = $users->where(['active' => 1])->pluck('email');
// ['alice@example.com', 'bob@example.com', ...]

// Group By with Having
$users->table('orders')
    ->select('user_id')
    ->groupBy('user_id')
    ->having('total', '>', 1000)
    ->get();
```

---

### Insert

```php
// Single row — returns last inserted ID
$id = $users->insert([
    'name'   => 'Alice',
    'email'  => 'alice@example.com',
    'active' => 1,
]);

// Multiple rows — returns last inserted ID
// Automatically chunked into batches of 1000
$users->insert([
    ['name' => 'Bob',   'email' => 'bob@example.com'],
    ['name' => 'Carol', 'email' => 'carol@example.com'],
    ['name' => 'Dave',  'email' => 'dave@example.com'],
]);
```

All rows in a batch must have identical column keys. Passing rows with different keys throws `InvalidArgumentException`.

---

### Update

Update requires a WHERE clause. Calling `update()` without one throws `InvalidArgumentException`.

```php
// Update single row
$affected = $users
    ->where(['id' => 1])
    ->update(['name' => 'Alice Smith', 'active' => 1]);

// Update multiple rows
$affected = $users
    ->where(['role' => 'guest'])
    ->update(['active' => 0]);

// Update with JOIN
$affected = $users
    ->join('profiles', 'users.id', '=', 'profiles.user_id')
    ->where(['users.active' => 0])
    ->update(['users.deleted_at' => date('Y-m-d H:i:s')]);
```

---

### Delete

Delete requires a WHERE clause. Calling `delete()` without one throws `InvalidArgumentException`.

```php
// Hard delete
$affected = $users->where(['id' => 1])->delete();

// Delete multiple
$affected = $users->whereIn('id', [4, 5, 6])->delete();
```

---

### Soft Delete

Mark rows as deleted by setting a `deleted_at` timestamp instead of removing them.

```php
// Soft delete — sets deleted_at to current timestamp
$users->where(['id' => 1])->soft()->delete();

// Restore — sets deleted_at back to null
$users->where(['id' => 1])->restore();

// Query only soft-deleted rows
$users->withTrash()->get();

// Query only non-deleted rows
$users->withoutTrash()->get();
```

Override the soft delete column in your model:

```php
protected string $deletedAtColumn = 'removed_at';
```

---

### Increment & Decrement

```php
// Increment login_count by 1 for a specific user
$users->where(['id' => 1])->increment('login_count');

// Increment by a custom amount
$users->where(['id' => 1])->increment('credits', 50);

// Decrement
$users->where(['id' => 1])->decrement('credits', 10);

// Using table.column notation
$users->where(['id' => 1])->increment('users.views', 1);
```

Both methods require a WHERE clause.

---

### Chunking

Process large result sets without loading all rows into memory at once.

```php
$users->where(['active' => 1])->chunk(100, function (array $rows) {
    foreach ($rows as $row) {
        // process each row
    }
});
```

Each chunk is fetched in a separate query. The loop stops automatically when no more rows are returned.

---

### Transactions

```php
$users->transaction(function (Model $model) {
    $id = $model->insert(['name' => 'Alice', 'email' => 'a@b.com']);
    $model->table('orders')->insert(['user_id' => $id, 'total' => 99.99]);
});
```

Automatically rolls back and rethrows as `RuntimeException` on any exception.

---

### Raw Queries

```php
// Prepared statement — returns PDOStatement
$stmt = $users->execute(
    'SELECT * FROM users WHERE email LIKE ? AND active = ?',
    ['%@example.com', 1]
);
$rows = $stmt->fetchAll();

// Aggregate
$count = $users->execute('SELECT COUNT(*) FROM users')->fetchColumn();

// JOIN
$rows = $users->execute(
    'SELECT u.name, p.title FROM users u JOIN posts p ON p.user_id = u.id WHERE u.id = ?',
    [1]
)->fetchAll();
```

---

### Debugging

Preview the SQL that would be executed with bindings filled in. Does not execute anything.

```php
$sql = $users
    ->where(['active' => 1])
    ->order('created_at', 'DESC')
    ->limit(10)
    ->debug();

// Returns: SELECT * FROM `users` WHERE `active` = 1 ORDER BY `created_at` DESC LIMIT 10
echo $sql;
```

---

### UID Generation

Generate a unique, collision-safe string ID and verify it does not already exist in the database.

```php
$uid = $users->uid();
// Returns: "UID-A1B2C3-D4E5F6-G7H8I9-J0K1L2-483920"

// Custom max attempts (default 10)
$uid = $users->uid(5);
```

Override the UID column name in your model:

```php
protected string $uid = 'uid';
```

---

### Type Casting

Declare a `$casts` array in your model to automatically convert column values after fetching.

```php
protected array $casts = [
    'id'          => 'int',
    'active'      => 'bool',
    'score'       => 'float',
    'preferences' => 'json',
    'permissions' => 'serialize',
    'name'        => 'string',
];
```

| Cast type | Input from DB | Output |
|---|---|---|
| `int` / `integer` | `"42"` | `42` |
| `float` / `double` | `"3.14"` | `3.14` |
| `bool` / `boolean` | `"0"`, `""`, `null` | `false` — all others `true` |
| `json` / `array` | `'{"a":1}'` | `['a' => 1]` |
| `serialize` | `'a:1:{...}'` | original PHP value |
| `string` | `42` | `"42"` |

Casting is applied automatically on `get()`, `first()`, `firstOrFail()`, `chunk()`, and `pluck()`.

**Note:** Values must be serialized manually before `insert()` / `update()`:

```php
$users->where(['id' => 1])->update([
    'preferences' => json_encode(['theme' => 'dark']),
    'permissions' => serialize(['read', 'write']),
]);
```

---

## Schema Builder

### Creating Tables

```php
use Laika\Model\Schema\Schema;
use Laika\Model\Schema\Blueprint;

// Create — throws if table already exists
Schema::on()->create('users', function (Blueprint $t) {
    $t->id();
    $t->string('name', 100);
    $t->string('email');
    $t->timestamps();
});

// Create if not exists — safe to run on every deploy
Schema::on()->createIfNotExists('users', function (Blueprint $t) {
    $t->id();
    $t->uid();
    $t->string('name', 100);
    $t->string('email');
    $t->boolean('active')->default(true);
    $t->timestamps();
});

// With MySQL table options
Schema::on()->create('logs', function (Blueprint $t) {
    $t->id();
    $t->text('message');
    $t->timestamps();
}, [
    'engine'    => 'InnoDB',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
```

---

### Column Types

```php
Schema::on()->create('showcase', function (Blueprint $t) {

    // Auto-increment primary keys
    $t->id();                          // INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
    $t->bigId();                       // BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
    $t->id('custom_id');               // custom PK name

    // UID
    $t->uid();                         // CHAR(38) — for string UIDs
    $t->uid('custom_uid');             // custom column name

    // Integers
    $t->integer('views');
    $t->bigInteger('large_number');
    $t->smallInteger('rating');
    $t->tinyInteger('flag');
    $t->unsignedInteger('score');
    $t->unsignedBigInteger('ref_id');

    // Decimals
    $t->float('latitude');
    $t->double('longitude');
    $t->decimal('price', 10, 2);       // DECIMAL(10,2)

    // Boolean
    $t->boolean('is_active');

    // Strings
    $t->char('country_code', 3);       // CHAR(3)
    $t->string('title');               // VARCHAR(255)
    $t->string('slug', 200);           // VARCHAR(200)
    $t->text('summary');
    $t->mediumText('content');
    $t->longText('body');
    $t->serialize('payload');          // TEXT — use for serialized PHP data

    // Enum & Set (MySQL native; CHECK constraint on other drivers)
    $t->enum('status', ['active', 'inactive', 'banned']);
    $t->set('roles', ['admin', 'editor', 'viewer']);

    // Date & Time
    $t->date('birth_date');
    $t->time('start_time');
    $t->dateTime('published_at');
    $t->timestamp('last_login');

    // Other
    $t->json('meta');
    $t->binary('file_data');

    // Helpers
    $t->timestamps();                  // created_at + updated_at (both nullable)
    $t->deleted();                     // deleted_at (nullable, for soft deletes)
    $t->deleted('removed_at');         // custom column name
});
```

---

### Column Modifiers

Chain modifiers after any column definition:

```php
$t->string('phone')->nullable();
$t->integer('stock')->default(0);
$t->string('status')->default('active');
$t->decimal('price', 10, 2)->unsigned()->default(0.00);
$t->text('notes')->nullable()->comment('Internal use only');
$t->integer('count')->unsigned()->autoIncrement();
```

| Modifier | Description |
|---|---|
| `->nullable()` | Allow NULL values |
| `->default($value)` | Set a default value |
| `->unsigned()` | Mark column as unsigned (integers) |
| `->autoIncrement()` | Add auto-increment |
| `->comment('...')` | Add a column comment (MySQL only) |

---

### Indexes & Constraints

```php
Schema::on()->create('posts', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('user_id');
    $t->string('slug', 200);
    $t->string('status')->default('draft');
    $t->timestamps();

    // Single column unique
    $t->unique(['slug']);

    // Composite unique with custom name
    $t->unique(['user_id', 'slug'], 'uq_user_slug');

    // Index
    $t->index(['status']);

    // Composite index with custom name
    $t->index(['user_id', 'status'], 'idx_user_status');

    // Composite primary key (no auto-increment id)
    $t->primary(['user_id', 'slug']);
});
```

---

### Foreign Keys

```php
Schema::on()->create('posts', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('user_id');
    $t->unsignedBigInteger('category_id')->nullable();
    $t->string('title');
    $t->timestamps();

    // Basic foreign key
    $t->foreign('user_id')
      ->references('id')
      ->on('users');

    // With cascade rules
    $t->foreign('user_id')
      ->references('id')
      ->on('users')
      ->onDelete('CASCADE')
      ->onUpdate('CASCADE');

    // Set null on delete
    $t->foreign('category_id')
      ->references('id')
      ->on('categories')
      ->onDelete('SET NULL');

    // Custom constraint name
    $t->foreign('user_id')
      ->references('id')
      ->on('users')
      ->onDelete('CASCADE')
      ->name('fk_posts_user');
});
```

Available `onDelete()` / `onUpdate()` actions: `CASCADE`, `SET NULL`, `RESTRICT`, `NO ACTION`, `SET DEFAULT`

---

### Modifying Tables

Add columns to an existing table:

```php
Schema::on()->table('users', function (Blueprint $t) {
    $t->string('phone', 20)->nullable();
    $t->string('avatar')->nullable();
    $t->tinyInteger('email_verified')->default(0);
});
```

> **Note:** Only `ADD COLUMN` is supported across all drivers. `DROP COLUMN` and `RENAME COLUMN` require `Schema::statement()` and are driver-specific.

---

### Dropping Tables

```php
Schema::on()->drop('sessions');           // error if table does not exist
Schema::on()->dropIfExists('cache');      // safe — no error if missing
```

---

### Renaming Tables

```php
Schema::on()->rename('user_roles', 'role_user');
```

---

### Inspecting Tables

```php
if (!Schema::on()->hasTable('users')) {
    Schema::on()->create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
    });
}

if (!Schema::on()->hasColumn('users', 'phone')) {
    Schema::on()->table('users', function (Blueprint $t) {
        $t->string('phone')->nullable();
    });
}
```

---

### Raw Statements

```php
Schema::on()->statement('CREATE FULLTEXT INDEX idx_search ON posts (title, body)');
Schema::on()->statement('PRAGMA foreign_keys = ON');       // SQLite
Schema::on()->statement('ALTER TABLE users MODIFY COLUMN bio MEDIUMTEXT');
```

---

### Multiple Connections

All Schema methods are available on any registered connection via `Schema::on('name')`:

```php
Schema::on('default')->create('users', function (Blueprint $t) { ... });
Schema::on('analytics')->create('events', function (Blueprint $t) { ... });
Schema::on('read')->hasTable('users');
Schema::on('warehouse')->dropIfExists('temp');
Schema::on('replica')->rename('orders_old', 'orders_archive');
```

---

### Custom Grammar

Register a grammar for a driver not built in (e.g. Oracle):

```php
use Laika\Model\Schema\Grammars\Grammar;
use Laika\Model\Schema\Blueprint;

class OracleGrammar extends Grammar
{
    public function compileCreate(Blueprint $blueprint): string { /* ... */ }
    public function compileAddColumns(Blueprint $blueprint): string { /* ... */ }
    public function compileDrop(string $table): string { /* ... */ }
    public function compileDropIfExists(string $table): string { /* ... */ }
    public function compileTableExists(): string { /* ... */ }
    public function compileColumnExists(): string { /* ... */ }
    public function compileRenameTable(string $from, string $to): string { /* ... */ }
}

Schema::registerGrammar('oci', OracleGrammar::class);

// Now Schema::on('oracle') uses your grammar
Schema::on('oracle')->create('users', function (Blueprint $t) {
    $t->id();
    $t->string('name');
});
```

---

## Log

Every query executed by `Model` and `Schema` is recorded in `Log`.

```php
use Laika\Model\Log;

// Get all queries grouped by connection
$all = Log::get();
// ['default' => ['SELECT * FROM ...', 'INSERT INTO ...'], 'read' => [...]]

// Count total queries across all connections
$total = Log::count();

// Add a manual entry
Log::add('SELECT 1', 'default');
Log::add(['SELECT 1', 'SELECT 2'], 'read');
```

---

## Driver Reference

| Driver key | Database | DSN format |
|---|---|---|
| `mysql` / `mariadb` | MySQL, MariaDB | `mysql:host=...;port=...;dbname=...;charset=...` |
| `pgsql` / `postgres` | PostgreSQL | `pgsql:host=...;port=...;dbname=...` |
| `sqlite` / `sqlite3` | SQLite | `sqlite:/path/to/file` or `sqlite::memory:` |
| `sqlsrv` | SQL Server | `sqlsrv:Server=...;Database=...` |
| `oci` / `oracle` | Oracle | `oci:dbname=//host:port/service` |
| `firebird` / `ibase` | Firebird | `firebird:dbname=host/port:/path/to/db` |

### Type mapping per driver

| Blueprint type | MySQL | PostgreSQL | SQLite | SQL Server |
|---|---|---|---|---|
| `id()` | `INT UNSIGNED AUTO_INCREMENT` | `SERIAL` | `INTEGER PRIMARY KEY AUTOINCREMENT` | `INT IDENTITY(1,1)` |
| `bigId()` | `BIGINT UNSIGNED AUTO_INCREMENT` | `BIGSERIAL` | `INTEGER PRIMARY KEY AUTOINCREMENT` | `BIGINT IDENTITY(1,1)` |
| `boolean()` | `TINYINT(1)` | `BOOLEAN` | `INTEGER` | `BIT` |
| `json()` | `JSON` | `JSONB` | `TEXT` | `NVARCHAR(MAX)` |
| `string()` | `VARCHAR(n)` | `VARCHAR(n)` | `VARCHAR(n)` | `NVARCHAR(n)` |
| `text()` | `TEXT` | `TEXT` | `TEXT` | `NVARCHAR(MAX)` |
| `longText()` | `LONGTEXT` | `TEXT` | `TEXT` | `NVARCHAR(MAX)` |
| `binary()` | `BLOB` | `BYTEA` | `BLOB` | `VARBINARY(MAX)` |
| `uuid()` / `uid()` | `CHAR(38)` | `UUID` | `TEXT` | `UNIQUEIDENTIFIER` |
| `dateTime()` | `DATETIME` | `TIMESTAMP` | `TEXT` | `DATETIME2` |
| `enum()` | `ENUM('a','b')` | `VARCHAR(255) CHECK (col IN ('a','b'))` | `VARCHAR(255) CHECK (col IN ('a','b'))` | `VARCHAR(255) CHECK (col IN ('a','b'))` |
| `set()` | `SET('a','b')` | `TEXT` | `TEXT` | `TEXT` |

### LIMIT / OFFSET per driver

| Driver | Syntax |
|---|---|
| MySQL / MariaDB / PostgreSQL / SQLite | `LIMIT n OFFSET m` |
| SQL Server | `SELECT TOP n` / `OFFSET m ROWS FETCH NEXT n ROWS ONLY` |
| Oracle 12c+ | `FETCH FIRST n ROWS ONLY` / `OFFSET m ROWS FETCH NEXT n ROWS ONLY` |
| Firebird | `ROWS n` / `ROWS start TO end` |

---

## Security

- **All values** are bound via PDO prepared statements — never interpolated into SQL.
- **All identifiers** (table names, column names) are validated against `/^[a-zA-Z_][a-zA-Z0-9_]*$/` and wrapped in driver-specific quote characters (`` ` `` for MySQL, `"` for PostgreSQL/SQLite, `[]` for SQL Server).
- **`table.column` notation** is handled correctly — the dot is never stripped.
- **WHERE operators** in `where()` and `having()` are validated against a strict allowlist: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`.
- **JOIN operators** are validated against: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`.
- **JOIN types** are validated against: `LEFT`, `RIGHT`, `INNER`.
- **ORDER direction** is validated — only `ASC` and `DESC` are accepted.
- `update()` and `delete()` require a WHERE clause — calling either without one throws `InvalidArgumentException`, preventing accidental full-table mutations.
- `unix_socket` is blocked for non-localhost hosts with a clear exception.
