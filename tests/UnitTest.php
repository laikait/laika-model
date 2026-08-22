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
}
