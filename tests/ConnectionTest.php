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

use PDO;
use PHPUnit\Framework\TestCase;
use Laika\Model\Connection;
use Laika\Model\Drivers\AbstractDriver;
use Laika\Model\Drivers\DriverFactory;
use Laika\Model\Drivers\DriverInterface;
use Laika\Model\Drivers\FirebirdDriver;
use Laika\Model\Drivers\MySqlDriver;
use Laika\Model\Drivers\OciDriver;
use Laika\Model\Drivers\PgSqlDriver;
use Laika\Model\Drivers\SqliteDriver;
use Laika\Model\Drivers\SqlSrvDriver;
use Laika\Model\Exceptions\ConnectionException;
use Laika\Model\Exceptions\DriverException;
use Laika\Model\Model;

/**
 * Registry and driver-layer tests.
 *
 * Everything here runs without a database server: the live-connection cases use
 * SQLite ":memory:", and the DSN builders are asserted as plain strings so all
 * six drivers are covered on every run.
 */
class ConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        Connection::purge();
    }

    protected function tearDown(): void
    {
        Connection::purge();
    }

    private function requireSqlite(): void
    {
        if (!in_array('pdo_sqlite', get_loaded_extensions(), true)) {
            $this->markTestSkipped('Extension pdo_sqlite is not loaded.');
        }
    }

    /** @return array<string,string> */
    private function memory(): array
    {
        return ['driver' => 'sqlite', 'database' => ':memory:'];
    }

    /** Number of tables with the given name visible on a SQLite handle. */
    private function tableCount(PDO $pdo, string $table): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Registry basics
    // -----------------------------------------------------------------------

    public function testAddRequiresADriverKey(): void
    {
        $this->expectException(ConnectionException::class);
        Connection::add(['host' => '127.0.0.1']);
    }

    public function testHasAndNamesTrackRegisteredConfigs(): void
    {
        $this->assertSame([], Connection::names());
        $this->assertFalse(Connection::has());

        Connection::add($this->memory());
        Connection::add($this->memory(), 'analytics');

        $this->assertTrue(Connection::has());
        $this->assertTrue(Connection::has('analytics'));
        $this->assertFalse(Connection::has('missing'));
        $this->assertSame(['default', 'analytics'], Connection::names());
    }

    public function testConfigReturnsWhatWasRegistered(): void
    {
        $config = ['driver' => 'sqlite', 'database' => ':memory:', 'options' => []];
        Connection::add($config, 'reporting');

        $this->assertSame($config, Connection::config('reporting'));
        $this->assertSame([], Connection::config('never-registered'));
    }

    public function testGetOnUnregisteredNameThrows(): void
    {
        $this->expectException(ConnectionException::class);
        Connection::get('nope');
    }

    public function testGetReturnsTheSameInstanceUntilClosed(): void
    {
        $this->requireSqlite();
        Connection::add($this->memory());

        $first = Connection::get();
        $this->assertSame($first, Connection::get(), 'get() must memoise the PDO instance');

        Connection::close();
        $this->assertNotSame($first, Connection::get(), 'close() must force a new instance');
    }

    public function testReconnectReplacesTheLiveInstance(): void
    {
        $this->requireSqlite();
        Connection::add($this->memory());

        $first = Connection::get();
        $this->assertNotSame($first, Connection::reconnect());
    }

    public function testCloseAllDropsInstancesButKeepsConfigs(): void
    {
        $this->requireSqlite();
        Connection::add($this->memory());
        Connection::add($this->memory(), 'analytics');
        Connection::get();
        Connection::get('analytics');

        Connection::closeAll();

        $this->assertSame(['default', 'analytics'], Connection::names(), 'closeAll() must keep configs');
        $this->assertInstanceOf(PDO::class, Connection::get());
    }

    public function testPurgeClearsEverythingIncludingTheDefaultName(): void
    {
        Connection::add($this->memory(), 'analytics');
        Connection::setDefault('analytics');

        Connection::purge();

        $this->assertSame([], Connection::names());
        $this->assertSame('default', Connection::getDefault());
    }

    // -----------------------------------------------------------------------
    // Default connection name
    // -----------------------------------------------------------------------

    public function testSetDefaultRedirectsNameOmission(): void
    {
        $this->requireSqlite();
        Connection::add(['driver' => 'sqlite', 'database' => ':memory:'], 'primary');
        Connection::add(['driver' => 'mysql', 'host' => 'db', 'database' => 'app'], 'legacy');

        Connection::setDefault('primary');

        $this->assertSame('primary', Connection::getDefault());
        $this->assertTrue(Connection::has());
        $this->assertSame('sqlite', Connection::driver());
        $this->assertSame(Connection::get('primary'), Connection::get());
    }

    public function testSetDefaultRejectsAnUnregisteredName(): void
    {
        $this->expectException(ConnectionException::class);
        Connection::setDefault('never-registered');
    }

    // -----------------------------------------------------------------------
    // driver() — regression coverage for the stale/uninitialised driver map
    // -----------------------------------------------------------------------

    public function testDriverIsResolvableBeforeConnecting(): void
    {
        // No get() call — driver() used to throw "Initiate First" here.
        Connection::add(['driver' => 'mysql', 'host' => 'db', 'database' => 'app']);

        $this->assertSame('mysql', Connection::driver());
    }

    public function testDriverOnUnregisteredNameThrows(): void
    {
        $this->expectException(ConnectionException::class);
        Connection::driver('nope');
    }

    /**
     * Aliases are accepted in config but driver() always reports the canonical
     * name — this is the contract Schema/Model/Backup all depend on.
     *
     * @dataProvider aliasProvider
     */
    public function testDriverReportsCanonicalNamesForAliases(string $alias, string $canonical): void
    {
        Connection::add(['driver' => $alias, 'host' => 'db', 'database' => 'app']);

        $this->assertSame($canonical, Connection::driver());
    }

    /** @return array<string,array{string,string}> */
    public static function aliasProvider(): array
    {
        return [
            'mariadb'  => ['mariadb', 'mysql'],
            'postgres' => ['postgres', 'pgsql'],
            'sqlite3'  => ['sqlite3', 'sqlite'],
            'oracle'   => ['oracle', 'oci'],
            'ibase'    => ['ibase', 'firebird'],
        ];
    }

    public function testDriverDoesNotGoStaleAfterPurgeAndReAdd(): void
    {
        Connection::add(['driver' => 'mysql', 'host' => 'db', 'database' => 'app']);
        $this->assertSame('mysql', Connection::driver());

        Connection::purge();
        Connection::add(['driver' => 'pgsql', 'host' => 'db', 'database' => 'app']);

        $this->assertSame('pgsql', Connection::driver(), 'purge() must clear the memoised driver name');
    }

    public function testDriverDoesNotGoStaleAfterReAddingUnderTheSameName(): void
    {
        Connection::add(['driver' => 'mysql', 'host' => 'db', 'database' => 'app'], 'swap');
        $this->assertSame('mysql', Connection::driver('swap'));

        Connection::add(['driver' => 'sqlsrv', 'host' => 'db', 'database' => 'app'], 'swap');

        $this->assertSame('sqlsrv', Connection::driver('swap'));
    }

    // -----------------------------------------------------------------------
    // Two simultaneous connections
    // -----------------------------------------------------------------------

    public function testTwoConnectionsAreIsolated(): void
    {
        $this->requireSqlite();

        Connection::add($this->memory(), 'alpha');
        Connection::add($this->memory(), 'beta');

        $alpha = Connection::get('alpha');
        $beta  = Connection::get('beta');

        $this->assertNotSame($alpha, $beta, 'Each name must get its own PDO instance');

        $alpha->exec('CREATE TABLE only_in_alpha (id INTEGER PRIMARY KEY)');
        $beta->exec('CREATE TABLE only_in_beta (id INTEGER PRIMARY KEY)');

        $this->assertSame(1, $this->tableCount($alpha, 'only_in_alpha'));
        $this->assertSame(0, $this->tableCount($alpha, 'only_in_beta'), 'beta leaked into alpha');
        $this->assertSame(1, $this->tableCount($beta, 'only_in_beta'));
        $this->assertSame(0, $this->tableCount($beta, 'only_in_alpha'), 'alpha leaked into beta');
    }

    public function testModelsOnDifferentConnectionsWriteToDifferentDatabases(): void
    {
        $this->requireSqlite();

        Connection::add($this->memory(), 'alpha');
        Connection::add($this->memory(), 'beta');

        foreach (['alpha', 'beta'] as $name) {
            Connection::get($name)->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');
        }

        (new Model('alpha'))->table('notes')->insert(['body' => 'from alpha']);

        $alphaRows = (new Model('alpha'))->table('notes')->get();
        $betaRows  = (new Model('beta'))->table('notes')->get();

        $this->assertCount(1, $alphaRows);
        $this->assertSame('from alpha', $alphaRows[0]['body']);
        $this->assertCount(0, $betaRows, 'The write must not reach the other connection');
    }

    public function testCloseOnOneConnectionLeavesTheOtherAlone(): void
    {
        $this->requireSqlite();

        Connection::add($this->memory(), 'alpha');
        Connection::add($this->memory(), 'beta');

        $alpha = Connection::get('alpha');
        $beta  = Connection::get('beta');

        Connection::close('alpha');

        $this->assertNotSame($alpha, Connection::get('alpha'));
        $this->assertSame($beta, Connection::get('beta'));
    }

    // -----------------------------------------------------------------------
    // Stale handle detection
    // -----------------------------------------------------------------------

    public function testGenerationAdvancesOnEveryMutation(): void
    {
        $before = Connection::generation();

        Connection::add($this->memory());
        $this->assertGreaterThan($before, Connection::generation());

        $afterAdd = Connection::generation();
        Connection::close();
        $this->assertGreaterThan($afterAdd, Connection::generation());
    }

    public function testModelPicksUpAReplacedConnection(): void
    {
        $this->requireSqlite();

        Connection::add($this->memory());
        Connection::get()->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');

        $model = new Model();
        $model->table('notes')->insert(['body' => 'first database']);

        // Re-register the same name against a brand-new in-memory database.
        Connection::add($this->memory());
        Connection::get()->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');

        $this->assertSame(
            Connection::get(),
            $model->pdo(),
            'Model must re-resolve its handle after the registry changed'
        );
        $this->assertCount(
            0,
            $model->table('notes')->get(),
            'Model kept querying the replaced database'
        );
    }

    // -----------------------------------------------------------------------
    // Transactions
    // -----------------------------------------------------------------------

    public function testNestedTransactionsUseSavepoints(): void
    {
        $this->requireSqlite();

        Connection::add($this->memory());
        Connection::get()->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');

        $model = new Model();

        $this->assertSame(0, Connection::transactionLevel());

        $result = $model->transaction(function (Model $outer) use ($model) {
            $outer->table('notes')->insert(['body' => 'outer']);
            $this->assertSame(1, Connection::transactionLevel());

            $model->transaction(function (Model $inner) {
                $this->assertSame(2, Connection::transactionLevel());
                $inner->table('notes')->insert(['body' => 'inner']);
            });

            $this->assertSame(1, Connection::transactionLevel());

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(0, Connection::transactionLevel());
        $this->assertCount(2, (new Model())->table('notes')->get());
    }

    public function testInnerTransactionFailureDoesNotDiscardTheOuterOne(): void
    {
        $this->requireSqlite();

        Connection::add($this->memory());
        Connection::get()->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT)');

        $model = new Model();

        $model->transaction(function (Model $outer) use ($model) {
            $outer->table('notes')->insert(['body' => 'keep me']);

            try {
                $model->transaction(function (Model $inner) {
                    $inner->table('notes')->insert(['body' => 'roll me back']);
                    throw new \RuntimeException('inner failure');
                });
                $this->fail('The inner transaction should have thrown.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('inner failure', $e->getMessage());
            }

            $this->assertSame(1, Connection::transactionLevel(), 'Outer transaction must still be open');
        });

        $rows = (new Model())->table('notes')->get();
        $this->assertCount(1, $rows, 'Only the inner insert should have been rolled back');
        $this->assertSame('keep me', $rows[0]['body']);
    }

    public function testCommitWithoutAnActiveTransactionThrows(): void
    {
        $this->requireSqlite();
        Connection::add($this->memory());

        $this->expectException(ConnectionException::class);
        Connection::commit();
    }

    public function testRollBackWithoutAnActiveTransactionThrows(): void
    {
        $this->requireSqlite();
        Connection::add($this->memory());

        $this->expectException(ConnectionException::class);
        Connection::rollBack();
    }

    // -----------------------------------------------------------------------
    // Timezone validation
    // -----------------------------------------------------------------------

    /** @dataProvider validTimezoneProvider */
    public function testAssertTimezoneAcceptsValidIdentifiers(string $timezone): void
    {
        $this->assertSame($timezone, Connection::assertTimezone($timezone));
    }

    /** @return array<string,array{string}> */
    public static function validTimezoneProvider(): array
    {
        return [
            'name'   => ['UTC'],
            'region' => ['Asia/Dhaka'],
            'offset' => ['+06:00'],
            'signed' => ['-05:30'],
        ];
    }

    /** @dataProvider invalidTimezoneProvider */
    public function testAssertTimezoneRejectsInjection(string $timezone): void
    {
        $this->expectException(ConnectionException::class);
        Connection::assertTimezone($timezone);
    }

    /** @return array<string,array{string}> */
    public static function invalidTimezoneProvider(): array
    {
        return [
            'empty'     => [''],
            'quote'     => ["UTC'; DROP TABLE users; --"],
            'semicolon' => ['UTC; SELECT 1'],
            'space'     => ['Asia Dhaka'],
        ];
    }

    // -----------------------------------------------------------------------
    // Driver options
    // -----------------------------------------------------------------------

    public function testUserOptionsOverrideDriverDefaults(): void
    {
        $driver  = new MySqlDriver();
        $options = $driver->getOptions([
            'options' => [
                PDO::ATTR_ERRMODE          => PDO::ERRMODE_SILENT,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::ATTR_TIMEOUT          => 7,
            ],
        ]);

        $this->assertSame(PDO::ERRMODE_SILENT, $options[PDO::ATTR_ERRMODE], 'User ERRMODE was discarded');
        $this->assertTrue($options[PDO::ATTR_EMULATE_PREPARES], 'User EMULATE_PREPARES was discarded');
        $this->assertSame(7, $options[PDO::ATTR_TIMEOUT]);
    }

    public function testDefaultOptionsApplyWhenNotOverridden(): void
    {
        $options = (new PgSqlDriver())->getOptions([]);

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
        $this->assertSame(PDO::FETCH_ASSOC, $options[PDO::ATTR_DEFAULT_FETCH_MODE]);
        $this->assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
    }

    public function testMySqlInitCommandSetsCharsetOnce(): void
    {
        $options = (new MySqlDriver())->getOptions(['charset' => 'utf8mb4']);
        $init    = $options[PDO::MYSQL_ATTR_INIT_COMMAND];

        $this->assertSame("SET NAMES 'utf8mb4'", $init);
    }

    public function testMySqlInitCommandCombinesCharsetAndTimezone(): void
    {
        $options = (new MySqlDriver())->getOptions(['charset' => 'latin1', 'timezone' => '+06:00']);

        $this->assertSame(
            "SET NAMES 'latin1', time_zone = '+06:00'",
            $options[PDO::MYSQL_ATTR_INIT_COMMAND]
        );
    }

    public function testMySqlRejectsAnInjectedTimezone(): void
    {
        $this->expectException(ConnectionException::class);
        (new MySqlDriver())->getOptions(['timezone' => "UTC'; DROP TABLE users; --"]);
    }

    // -----------------------------------------------------------------------
    // DSN builders — no server required
    // -----------------------------------------------------------------------

    public function testMySqlDsn(): void
    {
        $driver = new MySqlDriver();

        $this->assertSame(
            'mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4',
            $driver->buildDsn(['database' => 'app'])
        );

        $this->assertSame(
            'mysql:host=db.internal;port=3307;dbname=shop;charset=latin1',
            $driver->buildDsn([
                'host'     => 'db.internal',
                'port'     => 3307,
                'database' => 'shop',
                'charset'  => 'latin1',
            ])
        );
    }

    public function testMySqlUnixSocketDsn(): void
    {
        $this->assertSame(
            'mysql:unix_socket=/tmp/mysql.sock;dbname=app;charset=utf8mb4',
            (new MySqlDriver())->buildDsn(['database' => 'app', 'unix_socket' => '/tmp/mysql.sock'])
        );
    }

    public function testMySqlUnixSocketRejectsRemoteHosts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MySqlDriver())->buildDsn([
            'host'        => 'db.example.com',
            'database'    => 'app',
            'unix_socket' => '/tmp/mysql.sock',
        ]);
    }

    public function testPgSqlDsn(): void
    {
        $driver = new PgSqlDriver();

        $this->assertSame(
            'pgsql:host=127.0.0.1;port=5432;dbname=app',
            $driver->buildDsn(['database' => 'app'])
        );

        $this->assertSame(
            'pgsql:host=127.0.0.1;port=5432;dbname=app;sslmode=require',
            $driver->buildDsn(['database' => 'app', 'sslmode' => 'require'])
        );
    }

    public function testSqliteDsn(): void
    {
        $driver = new SqliteDriver();

        $this->assertSame('sqlite::memory:', $driver->buildDsn(['database' => ':memory:']));
        $this->assertSame('sqlite:/var/db/app.sqlite', $driver->buildDsn(['database' => '/var/db/app.sqlite']));
        $this->assertSame('sqlite:/var/db/app.sqlite', $driver->buildDsn(['path' => '/var/db/app.sqlite']));
    }

    public function testSqliteDsnRequiresAPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SqliteDriver())->buildDsn([]);
    }

    public function testSqlSrvDsn(): void
    {
        $driver = new SqlSrvDriver();

        $this->assertSame(
            'sqlsrv:Server=127.0.0.1;Database=app',
            $driver->buildDsn(['database' => 'app'])
        );

        $this->assertSame(
            'sqlsrv:Server=db,1433;Database=app;Encrypt=1;TrustServerCertificate=1',
            $driver->buildDsn([
                'host'                     => 'db',
                'port'                     => 1433,
                'database'                 => 'app',
                'encrypt'                  => true,
                'trust_server_certificate' => true,
            ])
        );
    }

    public function testOciDsn(): void
    {
        $driver = new OciDriver();

        $this->assertSame(
            'oci:dbname=//127.0.0.1:1521/ORCL;charset=AL32UTF8',
            $driver->buildDsn(['database' => 'ORCL'])
        );

        $this->assertSame('oci:dbname=MYTNS', $driver->buildDsn(['tns' => 'MYTNS']));
    }

    public function testFirebirdDsn(): void
    {
        $this->assertSame(
            'firebird:dbname=127.0.0.1/3050:/data/app.fdb;charset=UTF8',
            (new FirebirdDriver())->buildDsn(['database' => '/data/app.fdb'])
        );
    }

    // -----------------------------------------------------------------------
    // DriverFactory
    // -----------------------------------------------------------------------

    public function testFactoryRejectsAnUnknownDriver(): void
    {
        $this->expectException(DriverException::class);
        DriverFactory::make(['driver' => 'nosuchdb']);
    }

    public function testFactoryResolvesAliasesCaseInsensitively(): void
    {
        $this->assertInstanceOf(MySqlDriver::class, DriverFactory::make(['driver' => 'MariaDB']));
        $this->assertInstanceOf(SqliteDriver::class, DriverFactory::make(['driver' => 'SQLite3']));
    }

    public function testCustomDriverCanBeRegisteredAndUsed(): void
    {
        DriverFactory::register('laikadb', StubDriver::class);

        $this->assertInstanceOf(StubDriver::class, DriverFactory::make(['driver' => 'laikadb']));
        $this->assertContains('laikadb', DriverFactory::supported());

        Connection::add(['driver' => 'laikadb', 'database' => 'anything'], 'custom');
        $this->assertSame('stub', Connection::driver('custom'), 'Custom drivers report their own getName()');
    }

    public function testRegisterRejectsAClassThatIsNotADriver(): void
    {
        $this->expectException(DriverException::class);
        DriverFactory::register('bogus', \stdClass::class);
    }
}

/** Minimal custom driver used by testCustomDriverCanBeRegisteredAndUsed. */
final class StubDriver extends AbstractDriver implements DriverInterface
{
    public function getName(): string
    {
        return 'stub';
    }

    public function buildDsn(array $config): string
    {
        return 'sqlite::memory:';
    }
}
