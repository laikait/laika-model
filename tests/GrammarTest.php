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

namespace Laika\Model\Tests;

use PHPUnit\Framework\TestCase;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Expression;
use Laika\Model\Schema\Grammars\Grammar;
use Laika\Model\Schema\Grammars\MySqlGrammar;
use Laika\Model\Schema\Grammars\PgSqlGrammar;
use Laika\Model\Schema\Grammars\SqliteGrammar;
use Laika\Model\Schema\Grammars\SqlSrvGrammar;
use Laika\Model\Schema\Schema;
use Laika\Model\Connection;
use Laika\Model\Model;

/**
 * Golden-string tests for the DDL writers.
 *
 * These assert the exact SQL each grammar emits. No database server is needed,
 * so every driver is covered on every run — including sqlsrv, which nothing
 * else in the suite can reach.
 */
class GrammarTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::purge();
    }

    protected function tearDown(): void
    {
        Connection::purge();
    }

    /** @return array<string,array{Grammar}> */
    public static function grammarProvider(): array
    {
        return [
            'mysql'  => [new MySqlGrammar()],
            'pgsql'  => [new PgSqlGrammar()],
            'sqlite' => [new SqliteGrammar()],
            'sqlsrv' => [new SqlSrvGrammar()],
        ];
    }

    // -----------------------------------------------------------------------
    // UNSIGNED must not leak into drivers that have no such modifier
    // -----------------------------------------------------------------------

    /** @dataProvider grammarProvider */
    public function testOnlyMySqlEmitsUnsigned(Grammar $grammar): void
    {
        $bp = new Blueprint('t');
        $bp->unsignedInteger('hits');

        $sql      = $grammar->compileCreate($bp);
        $isMySql  = $grammar instanceof MySqlGrammar;

        if ($isMySql) {
            $this->assertStringContainsString('UNSIGNED', $sql);
        } else {
            $this->assertStringNotContainsString('UNSIGNED', $sql, 'UNSIGNED is a syntax error here');
        }
    }

    public function testPgSqlIdIsPlainSerial(): void
    {
        $bp = new Blueprint('users');
        $bp->id();

        // "SERIAL UNSIGNED" and a trailing AUTO_INCREMENT space were both bugs.
        $this->assertSame(
            "CREATE TABLE \"users\" (\n"
            . "  \"id\" SERIAL NOT NULL,\n"
            . "  PRIMARY KEY (\"id\")\n"
            . ");",
            (new PgSqlGrammar())->compileCreate($bp)
        );
    }

    // -----------------------------------------------------------------------
    // Indexes: inline on MySQL, standalone everywhere else
    // -----------------------------------------------------------------------

    public function testMySqlEmitsIndexesInline(): void
    {
        $bp = new Blueprint('users');
        $bp->string('email');
        $bp->index('email');

        $grammar = new MySqlGrammar();

        $this->assertStringContainsString('INDEX `idx_email` (`email`)', $grammar->compileCreate($bp));
        $this->assertSame([], $grammar->compileIndexes($bp), 'MySQL indexes are already inline');
    }

    /** @dataProvider grammarProvider */
    public function testNonMySqlEmitsIndexesAsSeparateStatements(Grammar $grammar): void
    {
        if ($grammar instanceof MySqlGrammar) {
            $this->markTestSkipped('MySQL emits indexes inline; covered separately.');
        }

        $bp = new Blueprint('users');
        $bp->string('email');
        $bp->index('email');

        $this->assertStringNotContainsString(
            'INDEX',
            $grammar->compileCreate($bp),
            'Inline INDEX inside CREATE TABLE is MySQL-only syntax'
        );

        $indexes = $grammar->compileIndexes($bp);
        $this->assertCount(1, $indexes);
        $this->assertStringStartsWith('CREATE INDEX ', $indexes[0]);
        $this->assertStringContainsString('ON ', $indexes[0]);
    }

    public function testCompileCreateIndexOutput(): void
    {
        $this->assertSame(
            'CREATE INDEX "idx_events_created_at" ON "events" ("created_at");',
            (new PgSqlGrammar())->compileCreateIndex('events', ['columns' => ['created_at'], 'name' => null])
        );

        $this->assertSame(
            'CREATE INDEX [idx_a_b] ON [t] ([a], [b]);',
            (new SqlSrvGrammar())->compileCreateIndex('t', ['columns' => ['a', 'b'], 'name' => null])
        );
    }

    /**
     * MySQL scopes index names to the table, PostgreSQL to the schema and
     * SQLite to the database. A mysqldump with `KEY userid (userid)` on eleven
     * tables produced eleven `idx_userid` and collided on the second one.
     */
    public function testIndexNamesDoNotCollideAcrossTables(): void
    {
        foreach ([new PgSqlGrammar(), new SqliteGrammar()] as $grammar) {
            $names = [];

            foreach (['tblemails', 'tblorders'] as $table) {
                $bp = new Blueprint($table);
                $bp->string('userid');
                $bp->index('userid');

                $names[] = $grammar->compileIndexes($bp)[0];
            }

            $this->assertNotSame(
                $names[0],
                $names[1],
                'Index names must be unique schema-wide on ' . $grammar::class
            );
        }
    }

    /**
     * PostgreSQL backs a UNIQUE constraint with a schema-level index and SQL
     * Server scopes constraint names to the schema, so `uq_asset_type` on two
     * tables collided the same way the indexes did.
     */
    public function testConstraintNamesDoNotCollideAcrossTables(): void
    {
        foreach ([new PgSqlGrammar(), new SqlSrvGrammar(), new SqliteGrammar()] as $grammar) {
            $sql = [];

            foreach (['tblfileassetsettings', 'tblassets'] as $table) {
                $bp = new Blueprint($table);
                $bp->string('asset_type');
                $bp->unique('asset_type');

                preg_match('/CONSTRAINT \S+/', $grammar->compileCreate($bp), $m);
                $sql[] = $m[0];
            }

            $this->assertNotSame(
                $sql[0],
                $sql[1],
                'Constraint names must be unique schema-wide on ' . $grammar::class
            );
        }
    }

    /** MySQL scopes constraint names per table — leave them alone. */
    public function testMySqlKeepsTheSourceConstraintName(): void
    {
        $bp = new Blueprint('users');
        $bp->string('email');
        $bp->unique('email');

        $this->assertStringContainsString(
            'CONSTRAINT `uq_email` UNIQUE',
            (new MySqlGrammar())->compileCreate($bp)
        );
    }

    /** MySQL and SQL Server scope index names per table — leave them alone. */
    public function testTableScopedDriversKeepTheSourceIndexName(): void
    {
        $this->assertSame(
            'CREATE INDEX [idx_email] ON [users] ([email]);',
            (new SqlSrvGrammar())->compileCreateIndex('users', ['columns' => ['email'], 'name' => null])
        );

        $bp = new Blueprint('users');
        $bp->string('email');
        $bp->index('email');
        $this->assertStringContainsString('INDEX `idx_email`', (new MySqlGrammar())->compileCreate($bp));
    }

    public function testAlreadyQualifiedIndexNameIsNotDoubled(): void
    {
        $this->assertSame(
            'CREATE INDEX "idx_users_email" ON "users" ("email");',
            (new PgSqlGrammar())->compileCreateIndex('users', ['columns' => ['email'], 'name' => 'idx_users_email'])
        );

        $this->assertSame(
            'CREATE INDEX "idx_users_email" ON "users" ("email");',
            (new PgSqlGrammar())->compileCreateIndex('users', ['columns' => ['email'], 'name' => 'users_email'])
        );
    }

    // -----------------------------------------------------------------------
    // Constraint-name prefixing (was a character-class ltrim)
    // -----------------------------------------------------------------------

    /**
     * ltrim('queue', 'uq_') is 'eue' and ltrim('did', 'idx_') is '' — the old
     * code stripped a character class rather than a prefix.
     *
     * @dataProvider nameProvider
     */
    public function testConstraintNamesKeepTheirColumnName(string $column, string $expectedUnique): void
    {
        $bp = new Blueprint('t');
        $bp->string($column);
        $bp->unique($column);

        $this->assertStringContainsString(
            "CONSTRAINT `{$expectedUnique}` UNIQUE",
            (new MySqlGrammar())->compileCreate($bp)
        );
    }

    /** @return array<string,array{string,string}> */
    public static function nameProvider(): array
    {
        return [
            'leading u/q chars' => ['queue', 'uq_queue'],
            'leading q'         => ['qty', 'uq_qty'],
            'ordinary'          => ['email', 'uq_email'],
            'underscore first'  => ['_internal', 'uq__internal'],
        ];
    }

    public function testIndexNameSurvivesCharacterClassStrip(): void
    {
        $bp = new Blueprint('t');
        $bp->string('did');
        $bp->index('did');

        // Was 'idx_' — every character of 'did' is in the class 'idx_'.
        $this->assertStringContainsString('INDEX `idx_did`', (new MySqlGrammar())->compileCreate($bp));
    }

    public function testExplicitPrefixIsNotDoubled(): void
    {
        $bp = new Blueprint('t');
        $bp->string('email');
        $bp->unique('email', 'uq_email');

        $sql = (new MySqlGrammar())->compileCreate($bp);

        $this->assertStringContainsString('`uq_email`', $sql);
        $this->assertStringNotContainsString('uq_uq_', $sql);
    }

    // -----------------------------------------------------------------------
    // Per-driver type corrections
    // -----------------------------------------------------------------------

    public function testPgSqlHasNoTinyIntOrBareDouble(): void
    {
        $bp = new Blueprint('t');
        $bp->tinyInteger('status');
        $bp->double('balance');

        $sql = (new PgSqlGrammar())->compileCreate($bp);

        $this->assertStringContainsString('"status" SMALLINT', $sql);
        $this->assertStringContainsString('"balance" DOUBLE PRECISION', $sql);
    }

    public function testSqlSrvDoubleAndTimestamp(): void
    {
        $bp = new Blueprint('t');
        $bp->double('balance');
        $bp->timestamp('seen_at');

        $sql = (new SqlSrvGrammar())->compileCreate($bp);

        $this->assertStringContainsString('[balance] FLOAT(53)', $sql);
        // A bare TIMESTAMP in T-SQL is a rowversion, not a point in time.
        $this->assertStringContainsString('[seen_at] DATETIME2', $sql);
    }

    // -----------------------------------------------------------------------
    // SQLite primary-key handling
    // -----------------------------------------------------------------------

    public function testSqliteIdIsIntegerPrimaryKeyWithNoDuplicatePk(): void
    {
        $bp = new Blueprint('users');
        $bp->id();
        $bp->string('name');

        $sql = (new SqliteGrammar())->compileCreate($bp);

        $this->assertStringContainsString('"id" INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $this->assertSame(1, substr_count($sql, 'PRIMARY KEY'), 'SQLite rejects two primary keys');
    }

    public function testSqliteKeepsAnExplicitCompositePrimaryKey(): void
    {
        $bp = new Blueprint('pivot');
        $bp->integer('user_id');
        $bp->integer('role_id');
        $bp->primary(['user_id', 'role_id']);

        // With no id() column there is no inline PK, so the table-level one must survive.
        $this->assertStringContainsString(
            'PRIMARY KEY ("user_id", "role_id")',
            (new SqliteGrammar())->compileCreate($bp)
        );
    }

    // -----------------------------------------------------------------------
    // Defaults
    // -----------------------------------------------------------------------

    /**
     * is_callable() is true for ordinary strings that happen to name a PHP
     * function, so DEFAULT 'time' used to emit the result of time().
     *
     * @dataProvider functionNameProvider
     */
    public function testStringDefaultsThatNamePhpFunctionsAreQuotedLiterally(string $value): void
    {
        $bp = new Blueprint('t');
        $bp->string('col')->default($value);

        $this->assertStringContainsString(
            "DEFAULT '{$value}'",
            (new MySqlGrammar())->compileCreate($bp)
        );
    }

    /** @return array<string,array{string}> */
    public static function functionNameProvider(): array
    {
        return [
            'time'    => ['time'],
            'count'   => ['count'],
            'strlen'  => ['strlen'],
            'sqrt'    => ['sqrt'],
        ];
    }

    public function testExpressionDefaultIsEmittedRaw(): void
    {
        $bp = new Blueprint('t');
        $bp->dateTime('created_at')->default(new Expression('CURRENT_TIMESTAMP'));

        $this->assertStringContainsString(
            'DEFAULT CURRENT_TIMESTAMP',
            (new PgSqlGrammar())->compileCreate($bp)
        );
    }

    public function testClosureDefaultIsEmittedRaw(): void
    {
        $bp = new Blueprint('t');
        $bp->dateTime('created_at')->default(fn () => 'CURRENT_TIMESTAMP');

        $this->assertStringContainsString(
            'DEFAULT CURRENT_TIMESTAMP',
            (new MySqlGrammar())->compileCreate($bp)
        );
    }

    /** @dataProvider grammarProvider */
    public function testNoTrailingWhitespaceOnAnyLine(Grammar $grammar): void
    {
        $bp = new Blueprint('users');
        $bp->id();
        $bp->string('email');
        $bp->timestamp('created_at');

        foreach (explode("\n", $grammar->compileCreate($bp)) as $line) {
            $this->assertSame(rtrim($line), $line, "Trailing whitespace in: [{$line}]");
        }
    }

    // -----------------------------------------------------------------------
    // Full-table golden strings
    // -----------------------------------------------------------------------

    /**
     * The "posts" table shape, defined once.
     *
     * fullBlueprint() feeds it to a grammar directly for the golden-string
     * tests; Schema::create() feeds it the same closure for the live one.
     */
    private function postsDefinition(): \Closure
    {
        return function (Blueprint $bp): void {
            $bp->id();
            $bp->string('title', 190);
            $bp->text('body');
            $bp->boolean('published');
            $bp->integer('author_id');
            $bp->unique('title');
            $bp->index('author_id');
            $bp->foreign('author_id')->reference('id')->on('users')->onDelete('cascade');
        };
    }

    private function fullBlueprint(): Blueprint
    {
        $bp = new Blueprint('posts');
        ($this->postsDefinition())($bp);

        return $bp;
    }

    public function testMySqlFullTable(): void
    {
        $this->assertSame(
            "CREATE TABLE `posts` (\n"
            . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
            . "  `title` VARCHAR(190) NOT NULL,\n"
            . "  `body` TEXT NOT NULL,\n"
            . "  `published` TINYINT(1) NOT NULL,\n"
            . "  `author_id` INT NOT NULL,\n"
            . "  PRIMARY KEY (`id`),\n"
            . "  CONSTRAINT `uq_title` UNIQUE (`title`),\n"
            . "  CONSTRAINT `fk_author_id` FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,\n"
            . "  INDEX `idx_author_id` (`author_id`)\n"
            . ");",
            (new MySqlGrammar())->compileCreate($this->fullBlueprint())
        );
    }

    public function testPgSqlFullTable(): void
    {
        $grammar = new PgSqlGrammar();
        $bp      = $this->fullBlueprint();

        $this->assertSame(
            "CREATE TABLE \"posts\" (\n"
            . "  \"id\" SERIAL NOT NULL,\n"
            . "  \"title\" VARCHAR(190) NOT NULL,\n"
            . "  \"body\" TEXT NOT NULL,\n"
            . "  \"published\" BOOLEAN NOT NULL,\n"
            . "  \"author_id\" INT NOT NULL,\n"
            . "  PRIMARY KEY (\"id\"),\n"
            . "  CONSTRAINT \"uq_posts_title\" UNIQUE (\"title\"),\n"
            . "  CONSTRAINT \"fk_posts_author_id\" FOREIGN KEY (\"author_id\") REFERENCES \"users\"(\"id\") ON DELETE CASCADE\n"
            . ");",
            $grammar->compileCreate($bp)
        );

        $this->assertSame(
            ['CREATE INDEX "idx_posts_author_id" ON "posts" ("author_id");'],
            $grammar->compileIndexes($bp)
        );
    }

    public function testSqliteFullTable(): void
    {
        $grammar = new SqliteGrammar();
        $bp      = $this->fullBlueprint();

        $this->assertSame(
            "CREATE TABLE \"posts\" (\n"
            . "  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n"
            . "  \"title\" VARCHAR(190) NOT NULL,\n"
            . "  \"body\" TEXT NOT NULL,\n"
            . "  \"published\" INTEGER NOT NULL,\n"
            . "  \"author_id\" INT NOT NULL,\n"
            . "  CONSTRAINT \"uq_posts_title\" UNIQUE (\"title\"),\n"
            . "  CONSTRAINT \"fk_posts_author_id\" FOREIGN KEY (\"author_id\") REFERENCES \"users\"(\"id\") ON DELETE CASCADE\n"
            . ");",
            $grammar->compileCreate($bp)
        );

        $this->assertSame(
            ['CREATE INDEX "idx_posts_author_id" ON "posts" ("author_id");'],
            $grammar->compileIndexes($bp)
        );
    }

    public function testSqlSrvFullTable(): void
    {
        $this->assertSame(
            "CREATE TABLE [posts] (\n"
            . "  [id] INT NOT NULL IDENTITY(1,1),\n"
            . "  [title] NVARCHAR(190) NOT NULL,\n"
            . "  [body] NVARCHAR(MAX) NOT NULL,\n"
            . "  [published] BIT NOT NULL,\n"
            . "  [author_id] INT NOT NULL,\n"
            . "  PRIMARY KEY ([id]),\n"
            . "  CONSTRAINT [uq_posts_title] UNIQUE ([title]),\n"
            . "  CONSTRAINT [fk_posts_author_id] FOREIGN KEY ([author_id]) REFERENCES [users]([id]) ON DELETE CASCADE\n"
            . ");",
            (new SqlSrvGrammar())->compileCreate($this->fullBlueprint())
        );
    }

    // -----------------------------------------------------------------------
    // The generated DDL must actually run
    // -----------------------------------------------------------------------

    public function testSqliteDdlExecutesAgainstARealDatabase(): void
    {
        if (!in_array('pdo_sqlite', get_loaded_extensions(), true)) {
            $this->markTestSkipped('Extension pdo_sqlite is not loaded.');
        }

        Connection::add(['driver' => 'sqlite', 'database' => ':memory:']);

        // Schema::on() is the public route to a grammar, so this exercises the
        // real path; the emitted SQL itself is pinned by the golden strings.
        Schema::on()->create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
        });

        Schema::on()->create('posts', $this->postsDefinition());

        $model = new Model();
        $model->table('users')->insert(['name' => 'a']);
        $model->table('posts')->insert([
            'title'     => 't',
            'body'      => 'b',
            'published' => 1,
            'author_id' => 1,
        ]);

        $this->assertSame('t', $model->table('posts')->where(['id' => 1])->first()['title']);
    }

    // -----------------------------------------------------------------------
    // Schema must pick the right grammar for the driver
    //
    // Every test above builds its grammar by hand, so none of them noticed that
    // Schema::$grammarMap was written in match-arm syntax ('a', 'b' => X) inside
    // an array literal. PHP read that as a bare auto-indexed value plus a
    // separate key, leaving no 'sqlite' and no 'mysql' entry at all.
    // -----------------------------------------------------------------------

    /** @return array<string,array{string,class-string<Grammar>}> */
    public static function driverGrammarProvider(): array
    {
        return [
            'mysql'   => ['mysql',   MySqlGrammar::class],
            'mariadb' => ['mariadb', MySqlGrammar::class],
            'pgsql'    => ['pgsql',    PgSqlGrammar::class],
            'postgres' => ['postgres', PgSqlGrammar::class],
            'sqlsrv'  => ['sqlsrv',  SqlSrvGrammar::class],
            'sqlite'  => ['sqlite',  SqliteGrammar::class],
            'sqlite3' => ['sqlite3', SqliteGrammar::class],
        ];
    }

    /**
     * @dataProvider driverGrammarProvider
     * @param class-string<Grammar> $expected
     */
    public function testGrammarMapResolvesEveryCanonicalDriver(string $driver, string $expected): void
    {
        $prop = new \ReflectionProperty(Schema::class, 'grammarMap');
        $prop->setAccessible(true);
        $map = $prop->getValue();

        $this->assertArrayHasKey($driver, $map, "No grammar mapped for driver [{$driver}]");
        $this->assertSame($expected, $map[$driver]);
    }

    public function testGrammarMapIsAWellFormedKeyValueArray(): void
    {
        $prop = new \ReflectionProperty(Schema::class, 'grammarMap');
        $prop->setAccessible(true);

        foreach ($prop->getValue() as $driver => $grammar) {
            // A match-arm literal leaks integer keys and bare driver-name values.
            $this->assertIsString($driver, 'Grammar map has an auto-indexed entry');
            $this->assertTrue(
                is_a($grammar, Grammar::class, true),
                "Grammar map value for [{$driver}] is not a Grammar class"
            );
        }
    }

    public function testUnknownDriverThrowsInsteadOfGuessingMySql(): void
    {
        $grammar = new \ReflectionMethod(Schema::class, 'grammar');
        $grammar->setAccessible(true);

        $schema = (new \ReflectionClass(Schema::class))->newInstanceWithoutConstructor();

        $driver = new \ReflectionProperty(Schema::class, 'connection');
        $driver->setAccessible(true);
        $driver->setValue($schema, 'no-such-connection');

        $this->expectException(\Throwable::class);
        $grammar->invoke($schema);
    }

    /** The exact table from the original SQLite failure report. */
    public function testSqliteCreateTableMatchesExpectedDdl(): void
    {
        $bp = new Blueprint('users', ['ifNotExists' => true]);
        $bp->bigId();
        $bp->string('username', 50);
        $bp->timestamp('created_at');

        $sql = (new SqliteGrammar())->compileCreate($bp);

        $this->assertSame(
            "CREATE TABLE IF NOT EXISTS \"users\" (\n"
            . "  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n"
            . "  \"username\" VARCHAR(50) NOT NULL,\n"
            . "  \"created_at\" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP\n"
            . ");",
            $sql
        );

        $this->assertStringNotContainsString('AUTO_INCREMENT', $sql);
        $this->assertStringNotContainsString('`', $sql, 'Backticks are a syntax error in SQLite');
    }

    // -----------------------------------------------------------------------
    // Foreign-key toggles must not fall back to MySQL
    //
    // Oracle and Firebird can connect and query, so they reach these methods
    // even though they have no Schema grammar. A MySQL default here is a
    // syntax error on both.
    // -----------------------------------------------------------------------

    /** @return array<string,array{string,?string,?string}> */
    public static function foreignKeyProvider(): array
    {
        return [
            'mysql'    => ['mysql',    'SET FOREIGN_KEY_CHECKS = 0', 'SET FOREIGN_KEY_CHECKS = 1'],
            'mariadb'  => ['mariadb',  'SET FOREIGN_KEY_CHECKS = 0', 'SET FOREIGN_KEY_CHECKS = 1'],
            'pgsql'    => ['pgsql',    'SET session_replication_role = replica', 'SET session_replication_role = DEFAULT'],
            'sqlite'   => ['sqlite',   'PRAGMA foreign_keys = OFF', 'PRAGMA foreign_keys = ON'],
            'sqlsrv'   => [
                'sqlsrv',
                'EXEC sp_msforeachtable "ALTER TABLE ? NOCHECK CONSTRAINT ALL"',
                'EXEC sp_msforeachtable "ALTER TABLE ? WITH CHECK CHECK CONSTRAINT ALL"',
            ],
            // No session-wide switch — must be a no-op, never MySQL syntax.
            'oci'      => ['oci',      null, null],
            'oracle'   => ['oracle',   null, null],
            'firebird' => ['firebird', null, null],
            'ibase'    => ['ibase',    null, null],
        ];
    }

    /** @dataProvider foreignKeyProvider */
    public function testForeignKeyToggleSqlPerDriver(string $driver, ?string $off, ?string $on): void
    {
        Connection::add(['driver' => $driver, 'host' => 'db', 'database' => 'app', 'path' => ':memory:']);

        $method = new \ReflectionMethod(Schema::class, 'foreignKeyCheckSql');
        $method->setAccessible(true);

        $schema = (new \ReflectionClass(Schema::class))->newInstanceWithoutConstructor();
        $conn   = new \ReflectionProperty(Schema::class, 'connection');
        $conn->setAccessible(true);
        $conn->setValue($schema, Connection::getDefault());

        $this->assertSame($off, $method->invoke($schema, false));
        $this->assertSame($on, $method->invoke($schema, true));

    }

    /** @dataProvider foreignKeyProvider */
    public function testForeignKeyToggleNeverEmitsMySqlSyntaxToOtherDrivers(string $driver): void
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('FOREIGN_KEY_CHECKS is correct on MySQL.');
        }

        Connection::add(['driver' => $driver, 'host' => 'db', 'database' => 'app', 'path' => ':memory:']);

        $method = new \ReflectionMethod(Schema::class, 'foreignKeyCheckSql');
        $method->setAccessible(true);

        $schema = (new \ReflectionClass(Schema::class))->newInstanceWithoutConstructor();
        $conn   = new \ReflectionProperty(Schema::class, 'connection');
        $conn->setAccessible(true);
        $conn->setValue($schema, Connection::getDefault());

        foreach ([true, false] as $enabled) {
            $this->assertStringNotContainsString(
                'FOREIGN_KEY_CHECKS',
                (string) $method->invoke($schema, $enabled),
                "MySQL syntax leaked to [{$driver}]"
            );
        }

    }
}
