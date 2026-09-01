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

use Laika\Model\Log;
use Laika\Model\Model;
use Laika\Model\Connection;
use Laika\Model\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Laika\Model\Schema\Blueprint;

/**
 * Integration tests driven by the DB_* environment variables exported by
 * .github/workflows/release.yml. With no DB_DRIVER set they fall back to an
 * in-memory SQLite database so the suite still exercises a real driver locally.
 */
class UnitTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::purge();
    }

    protected function tearDown(): void
    {
        Connection::purge();
    }

    /**
     * Build a connection config from the environment for the given driver.
     *
     * @return array<string,mixed>
     */
    private function configFor(string $driver): array
    {
        return match ($driver) {
            'mysql', 'pgsql' => [
                'driver'   => $driver,
                'host'     => getenv('DB_HOST') ?: '127.0.0.1',
                'username' => getenv('DB_USER') ?: 'root',
                'password' => getenv('DB_PASS') ?: '',
                'database' => getenv('DB_NAME') ?: 'test',
                'port'     => (int) (getenv('DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432)),
            ],
            'sqlite' => [
                'driver'   => 'sqlite',
                // DB_PATH is the database location for SQLite — ":memory:" in CI.
                'database' => getenv('DB_PATH') ?: ':memory:',
            ],
            default => throw new \InvalidArgumentException("Unsupported test driver [{$driver}]."),
        };
    }

    /**
     * The driver under test. Defaults to SQLite so the suite is meaningful
     * without a database server.
     */
    private function driver(): string
    {
        $driver = getenv('DB_DRIVER') ?: 'sqlite';

        if (!in_array("pdo_{$driver}", get_loaded_extensions(), true)) {
            $this->markTestSkipped("Extension pdo_{$driver} is not loaded.");
        }

        return $driver;
    }

    public function testConnectionTest(): void
    {
        $driver = $this->driver();

        Connection::add($this->configFor($driver));

        $this->assertInstanceOf(
            \PDO::class,
            Connection::get(),
            "Failed to initialize connection for {$driver}"
        );
    }

    public function testCreateTable(): void
    {
        $driver = $this->driver();

        Connection::add($this->configFor($driver));

        Schema::on()->dropIfExists('users');
        Schema::on()->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('full_name', 255);
            $t->timestamp('created_at');

            $t->index('created_at');
        });

        $model = new Model();
        $data  = ['full_name' => 'Showket Ahmed'];

        $inserted = $model->table('users')->insert($data);
        $this->assertTrue((bool) $inserted, "Failed to insert data in driver [{$driver}]");

        // Model::get() takes no arguments — the filter belongs in where().
        $row = $model->table('users')->where($data)->get();
        $this->assertNotEmpty($row, 'Could not find inserted row in the database');
        $this->assertSame('Showket Ahmed', $row[0]['full_name']);
    }

    // -----------------------------------------------------------------------
    // Multi-row INSERT batching
    //
    // Oracle and Firebird reject multi-row VALUES, so insert() must fall back
    // to one statement per row there. pdo_oci/pdo_firebird are rarely
    // installed, so the driver *name* is forced over a working SQLite handle —
    // single-row INSERT is valid in every dialect, which keeps the assertion
    // about batching rather than about syntax.
    // -----------------------------------------------------------------------

    /** @return array<string,array{string,int}> */
    public static function insertBatchingProvider(): array
    {
        return [
            'mysql batches'      => ['mysql',    1],
            'pgsql batches'      => ['pgsql',    1],
            'sqlite batches'     => ['sqlite',   1],
            'sqlsrv batches'     => ['sqlsrv',   1],
            'oci per row'        => ['oci',      3],
            'firebird per row'   => ['firebird', 3],
        ];
    }

    /** @dataProvider insertBatchingProvider */
    public function testInsertBatchesOnlyWhereMultiRowValuesIsSupported(
        string $forcedDriver,
        int $expectedStatements
    ): void {
        $this->driver(); // skips unless pdo_sqlite is available

        Connection::add($this->configFor('sqlite'));

        Schema::on()->dropIfExists('batching');
        Schema::on()->create('batching', function (Blueprint $t) {
            $t->id();
            $t->string('v', 50);
        });

        // Report a different driver while the PDO handle stays SQLite.
        $drivers = new \ReflectionProperty(Connection::class, 'drivers');
        $drivers->setAccessible(true);
        $drivers->setValue(null, [Connection::getDefault() => $forcedDriver]);

        $log = new \ReflectionProperty(Log::class, 'queries');
        $log->setAccessible(true);
        $log->setValue(null, []);

        $model = new Model();
        $rows  = [['v' => 'a'], ['v' => 'b'], ['v' => 'c']];
        $id    = $model->table('batching')->insert($rows);

        $this->assertSame(
            $expectedStatements,
            Log::count(),
            "Wrong statement count for [{$forcedDriver}]"
        );

        // Whichever path ran, every row must land exactly once.
        $stored = $model->table('batching')->get();
        $this->assertCount(3, $stored);
        $this->assertSame(['a', 'b', 'c'], array_column($stored, 'v'));

        // oci/firebird need an explicit sequence — don't invent an id for them.
        if (in_array($forcedDriver, ['oci', 'firebird'], true)) {
            $this->assertSame('', $id, 'Must not report a bare lastInsertId()');
        } else {
            $this->assertSame('3', $id);
        }
    }

    public function testInsertHandlesARaggedFinalChunk(): void
    {
        $this->driver();

        Connection::add($this->configFor('sqlite'));

        Schema::on()->dropIfExists('ragged');
        Schema::on()->create('ragged', function (Blueprint $t) {
            $t->id();
            $t->string('v', 50);
        });

        // 2500 rows = 1000 + 1000 + 500. The short final chunk has different
        // SQL, so the reused prepared statement must be re-prepared for it.
        $rows = [];
        for ($i = 1; $i <= 2500; $i++) {
            $rows[] = ['v' => 'row' . $i];
        }

        $model = new Model();
        $model->table('ragged')->insert($rows);

        $stored = $model->table('ragged')->get();
        $this->assertCount(2500, $stored);
        $this->assertSame('row1', $stored[0]['v']);
        $this->assertSame('row2500', $stored[2499]['v']);
    }

    // -----------------------------------------------------------------------
    // PostgreSQL insert()
    //
    // pdo_pgsql's bare lastInsertId() is SELECT lastval(), scoped to the session
    // and not to the table, so it used to report an id belonging to whatever
    // sequence the session touched last. insert() reads the value back through
    // RETURNING instead. These need a live server: run the suite with
    // DB_DRIVER=pgsql (plus DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS).
    // -----------------------------------------------------------------------

    /** Register a PostgreSQL connection, or skip. */
    private function requirePgsql(): void
    {
        if ((getenv('DB_DRIVER') ?: 'sqlite') !== 'pgsql') {
            $this->markTestSkipped('Set DB_DRIVER=pgsql to run the PostgreSQL insert tests.');
        }

        if (!in_array('pdo_pgsql', get_loaded_extensions(), true)) {
            $this->markTestSkipped('Extension pdo_pgsql is not loaded.');
        }

        Connection::add($this->configFor('pgsql'));
    }

    public function testPgsqlInsertBindsFalseAsABoolean(): void
    {
        $this->requirePgsql();

        Schema::on()->dropIfExists('pg_flags');
        Schema::on()->create('pg_flags', function (Blueprint $t) {
            $t->id();
            $t->string('name', 20);
            $t->boolean('active');
        });

        $model = new Model();

        // Bound as PARAM_STR this is '', and PostgreSQL rejects it outright:
        // SQLSTATE[22P02] invalid input syntax for type boolean: "".
        $model->table('pg_flags')->insert(['name' => 'off', 'active' => false]);
        $model->table('pg_flags')->insert(['name' => 'on',  'active' => true]);

        $off = $model->table('pg_flags')->where(['name' => 'off'])->first();
        $on  = $model->table('pg_flags')->where(['name' => 'on'])->first();

        // pdo_pgsql hands back a BOOLEAN as 'f' / 't'.
        $this->assertSame('f', $off['active']);
        $this->assertSame('t', $on['active']);
    }

    public function testPgsqlInsertReturnsTheRowsOwnId(): void
    {
        $this->requirePgsql();

        Schema::on()->dropIfExists('pg_people');
        Schema::on()->create('pg_people', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50);
        });

        $model = new Model();
        $id    = $model->table('pg_people')->insert(['name' => 'Showket']);

        $this->assertNotSame('', $id, 'RETURNING should have produced an id');

        // The id must address the row that was just written, not merely be
        // non-empty — that is exactly what lastval() failed to guarantee.
        $row = $model->table('pg_people')->find((int) $id);
        $this->assertNotNull($row);
        $this->assertSame('Showket', $row['name']);
    }

    public function testPgsqlMultiRowInsertReturnsTheLastId(): void
    {
        $this->requirePgsql();

        Schema::on()->dropIfExists('pg_batch');
        Schema::on()->create('pg_batch', function (Blueprint $t) {
            $t->id();
            $t->string('v', 20);
        });

        $model = new Model();
        $id    = $model->table('pg_batch')->insert([['v' => 'a'], ['v' => 'b'], ['v' => 'c']]);

        $row = $model->table('pg_batch')->find((int) $id);
        $this->assertNotNull($row);
        $this->assertSame('c', $row['v'], 'The id must belong to the last row inserted');
    }

    /**
     * A table with no id column at all — a join or log table. RETURNING names a
     * column that is not there, which PostgreSQL rejects with 42703 before
     * writing anything, so insert() drops the clause and sends the batch again.
     * The write must still land and the return value must be '' rather than an
     * id borrowed from some other sequence.
     */
    public function testPgsqlInsertWithoutAnIdColumnStillWrites(): void
    {
        $this->requirePgsql();

        Schema::on()->dropIfExists('pg_noid');
        Schema::on()->create('pg_noid', function (Blueprint $t) {
            $t->string('code', 20);
            $t->string('label', 20);
        });

        $model = new Model();
        $id    = $model->table('pg_noid')->insert(['code' => 'x', 'label' => 'ex']);

        $this->assertSame('', $id);
        $this->assertNotNull($model->table('pg_noid')->where(['code' => 'x'])->first());
    }

    /**
     * The silent failure lastval() used to produce: an insert into a table with
     * no sequence handed back the id generated by an unrelated earlier insert
     * in the same session.
     */
    public function testPgsqlInsertDoesNotBorrowAnotherTablesSequence(): void
    {
        $this->requirePgsql();

        Schema::on()->dropIfExists('pg_seq_owner');
        Schema::on()->create('pg_seq_owner', function (Blueprint $t) {
            $t->id();
            $t->string('v', 20);
        });

        Schema::on()->dropIfExists('pg_noid');
        Schema::on()->create('pg_noid', function (Blueprint $t) {
            $t->string('code', 20);
            $t->string('label', 20);
        });

        $model = new Model();

        // Burns pg_seq_owner's sequence, so lastval() is now defined.
        $owned = $model->table('pg_seq_owner')->insert(['v' => 'first']);
        $this->assertNotSame('', $owned);

        // Same session, different table, no sequence of its own.
        $borrowed = $model->table('pg_noid')->insert(['code' => 'y', 'label' => 'why']);

        $this->assertSame('', $borrowed);
        $this->assertNotSame($owned, $borrowed, 'lastval() leaked an id from another table');
    }

    /**
     * 1000 rows of a wide table overflows PostgreSQL's 65535-parameter limit, so
     * the chunk size has to be a placeholder budget divided by the row width.
     */
    public function testPgsqlInsertChunksWideRowsUnderTheParameterLimit(): void
    {
        $this->requirePgsql();

        $cols = 70;

        Schema::on()->dropIfExists('pg_wide');
        Schema::on()->create('pg_wide', function (Blueprint $t) use ($cols) {
            $t->id();
            for ($i = 0; $i < $cols; $i++) {
                $t->integer("c{$i}");
            }
        });

        $rows = [];
        for ($r = 0; $r < 1200; $r++) {
            $row = [];
            for ($i = 0; $i < $cols; $i++) {
                $row["c{$i}"] = $i;
            }
            $rows[] = $row;
        }

        $model = new Model();
        $model->table('pg_wide')->insert($rows);

        $this->assertSame(1200, $model->table('pg_wide')->count());
    }
}
