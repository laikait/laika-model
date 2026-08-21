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
use Laika\Model\Connection;
use Laika\Model\Converter\Converter;
use Laika\Model\Converter\TypeLexicon;
use Laika\Model\Converter\Warning;
use Laika\Model\Exceptions\ConverterException;

class ConverterTest extends TestCase
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

    // -----------------------------------------------------------------------
    // Construction
    // -----------------------------------------------------------------------

    public function testDriverAliasesAreCanonicalised(): void
    {
        $converter = new Converter('mariadb', 'postgres');

        $this->assertSame('mysql', $converter->sourceDialect());
        $this->assertSame('pgsql', $converter->targetDialect());
    }

    public function testUnknownSourceDriverThrows(): void
    {
        $this->expectException(ConverterException::class);
        new Converter('nosuchdb', 'pgsql');
    }

    public function testTargetWithoutAGrammarThrows(): void
    {
        // oci and firebird have drivers but no Schema grammar.
        $this->expectException(ConverterException::class);
        new Converter('mysql', 'oci');
    }

    // -----------------------------------------------------------------------
    // DDL
    // -----------------------------------------------------------------------

    public function testMySqlCreateTableToPostgres(): void
    {
        $sql = <<<'SQL'
        CREATE TABLE `users` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `email` varchar(190) NOT NULL,
          `nickname` varchar(60) DEFAULT NULL,
          `status` tinyint(4) NOT NULL DEFAULT 0,
          `balance` double DEFAULT NULL,
          `bio` longtext,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_email` (`email`),
          KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        SQL;

        $converter = new Converter('mysql', 'pgsql');

        $this->assertSame(
            "CREATE TABLE \"users\" (\n"
            . "  \"id\" SERIAL NOT NULL,\n"
            . "  \"email\" VARCHAR(190) NOT NULL,\n"
            // An explicit "DEFAULT NULL" in the source is preserved rather than
            // dropped — it is valid in every target and faithful to the input.
            . "  \"nickname\" VARCHAR(60) NULL DEFAULT NULL,\n"
            . "  \"status\" SMALLINT NOT NULL DEFAULT 0,\n"
            . "  \"balance\" DOUBLE PRECISION NULL DEFAULT NULL,\n"
            . "  \"bio\" TEXT NULL,\n"
            . "  \"created_at\" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
            . "  PRIMARY KEY (\"id\"),\n"
            . "  CONSTRAINT \"uq_users_email\" UNIQUE (\"email\")\n"
            . ");\n"
            . 'CREATE INDEX "idx_users_status" ON "users" ("status");',
            $converter->convert($sql)
        );

        $this->assertFalse($converter->report()->hasWarnings(), 'Plain DDL should convert cleanly');
    }

    public function testAutoIncrementBecomesTheTargetsOwnConvention(): void
    {
        $sql = 'CREATE TABLE t (`id` bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`));';

        $this->assertStringContainsString('BIGSERIAL', (new Converter('mysql', 'pgsql'))->convert($sql));
        $this->assertStringContainsString('IDENTITY(1,1)', (new Converter('mysql', 'sqlsrv'))->convert($sql));
        $this->assertStringContainsString(
            'INTEGER PRIMARY KEY AUTOINCREMENT',
            (new Converter('mysql', 'sqlite'))->convert($sql)
        );
    }

    public function testUnsignedIsDroppedForTargetsThatRejectIt(): void
    {
        $sql = 'CREATE TABLE t (`hits` int unsigned NOT NULL);';

        $this->assertStringNotContainsString('UNSIGNED', (new Converter('mysql', 'pgsql'))->convert($sql));
        $this->assertStringContainsString('UNSIGNED', (new Converter('mysql', 'mysql'))->convert($sql));
    }

    public function testEnumIsEmulatedOnTargetsWithoutIt(): void
    {
        $sql = "CREATE TABLE t (`role` enum('admin','user') NOT NULL);";

        $this->assertStringContainsString(
            "CHECK (role IN ('admin', 'user'))",
            (new Converter('mysql', 'pgsql'))->convert($sql)
        );
        $this->assertStringContainsString(
            "ENUM('admin', 'user')",
            (new Converter('pgsql', 'mysql'))->convert("CREATE TABLE t (role varchar(20));")
                ? (new Converter('mysql', 'mysql'))->convert($sql)
                : ''
        );
    }

    public function testForeignKeyIsCarriedAcross(): void
    {
        $sql = <<<'SQL'
        CREATE TABLE `posts` (
          `id` int NOT NULL,
          `author_id` int NOT NULL,
          CONSTRAINT `fk_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
        );
        SQL;

        $out = (new Converter('mysql', 'pgsql'))->convert($sql);

        $this->assertStringContainsString(
            'CONSTRAINT "fk_posts_author" FOREIGN KEY ("author_id") REFERENCES "users"("id") ON DELETE CASCADE ON UPDATE RESTRICT',
            $out
        );
    }

    public function testCompositeForeignKeyIsReportedNotSilentlyDropped(): void
    {
        $sql = <<<'SQL'
        CREATE TABLE t (
          a int NOT NULL,
          b int NOT NULL,
          FOREIGN KEY (a, b) REFERENCES other (x, y)
        );
        SQL;

        $converter = new Converter('mysql', 'pgsql');
        $converter->convert($sql);

        $warnings = $converter->report()->warningsOfLevel(Warning::LEVEL_LOSSY);

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('composite foreign key', $warnings[0]->reason);
    }

    public function testCheckConstraintIsReported(): void
    {
        $converter = new Converter('mysql', 'pgsql');
        $converter->convert('CREATE TABLE t (a int, CHECK (a > 0));');

        $this->assertStringContainsString(
            'CHECK constraint dropped',
            (string) $converter->report()->warnings()[0]
        );
    }

    public function testUnknownTypeIsCarriedThroughWithAWarning(): void
    {
        $converter = new Converter('pgsql', 'mysql');
        $out       = $converter->convert('CREATE TABLE places (id int, geom GEOMETRY NOT NULL, net INET);');

        // Carried verbatim rather than guessed at or dropped.
        $this->assertStringContainsString('`geom` GEOMETRY NOT NULL', $out);
        $this->assertStringContainsString('`net` INET', $out);

        $reasons = array_map(fn(Warning $w) => $w->reason, $converter->report()->warnings());
        $this->assertStringContainsString('GEOMETRY', implode(' ', $reasons));
        $this->assertStringContainsString('INET', implode(' ', $reasons));
    }

    public function testPostgresSerialToMySqlAutoIncrement(): void
    {
        $sql = <<<'SQL'
        CREATE TABLE "users" (
            "id" SERIAL NOT NULL,
            "email" character varying(190) NOT NULL,
            "score" double precision,
            "meta" jsonb,
            PRIMARY KEY ("id")
        );
        SQL;

        $out = (new Converter('pgsql', 'mysql'))->convert($sql);

        $this->assertStringContainsString('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT', $out);
        $this->assertStringContainsString('`email` VARCHAR(190) NOT NULL', $out);
        $this->assertStringContainsString('`score` DOUBLE', $out);
        $this->assertStringContainsString('`meta` JSON', $out);
    }

    public function testStandaloneCreateIndexIsTranslated(): void
    {
        $out = (new Converter('pgsql', 'mysql'))->convert('CREATE INDEX idx_email ON users (email);');

        $this->assertSame('CREATE INDEX `idx_email` ON `users` (`email`);', $out);
    }

    public function testDropTableListIsSplitPerTable(): void
    {
        $out = (new Converter('mysql', 'pgsql'))->convert('DROP TABLE IF EXISTS `a`, `b`;');

        $this->assertSame(
            "DROP TABLE IF EXISTS \"a\";\nDROP TABLE IF EXISTS \"b\";",
            $out
        );
    }

    // -----------------------------------------------------------------------
    // INSERT and literals
    // -----------------------------------------------------------------------

    public function testExtendedInsertIsSplitIntoRows(): void
    {
        $sql = "INSERT INTO `t` (`a`, `b`) VALUES (1,'x'),(2,'y'),(3,'z');";

        $this->assertSame(
            "INSERT INTO \"t\" (\"a\", \"b\") VALUES (1, 'x');\n"
            . "INSERT INTO \"t\" (\"a\", \"b\") VALUES (2, 'y');\n"
            . "INSERT INTO \"t\" (\"a\", \"b\") VALUES (3, 'z');",
            (new Converter('mysql', 'pgsql'))->convert($sql)
        );
    }

    public function testMySqlBackslashEscapesBecomeStandardDoubling(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('it\\'s');";

        $this->assertSame(
            "INSERT INTO \"t\" (\"a\") VALUES ('it''s');",
            (new Converter('mysql', 'pgsql'))->convert($sql)
        );
    }

    public function testExistingDoubledQuotesSurvive(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('quo''ted');";

        $this->assertSame(
            "INSERT INTO \"t\" (\"a\") VALUES ('quo''ted');",
            (new Converter('mysql', 'pgsql'))->convert($sql)
        );
    }

    /** @dataProvider hexTargetProvider */
    public function testHexBlobLiteralPerTarget(string $target, string $expected): void
    {
        $out = (new Converter('mysql', $target))->convert('INSERT INTO t (a) VALUES (0xDEADBEEF);');

        $this->assertStringContainsString($expected, $out);
    }

    /** @return array<string,array{string,string}> */
    public static function hexTargetProvider(): array
    {
        return [
            'pgsql'  => ['pgsql', "'\\xdeadbeef'::bytea"],
            'sqlite' => ['sqlite', "X'DEADBEEF'"],
            'mysql'  => ['mysql', '0xDEADBEEF'],
        ];
    }

    public function testBooleanColumnGetsATrueLiteralInPostgres(): void
    {
        $sql = "CREATE TABLE t (`flag` boolean NOT NULL);\n"
            . 'INSERT INTO t (`flag`) VALUES (1),(0);';

        $out = (new Converter('mysql', 'pgsql'))->convert($sql);

        $this->assertStringContainsString('VALUES (TRUE);', $out);
        $this->assertStringContainsString('VALUES (FALSE);', $out);
    }

    public function testBooleanStaysNumericForTargetsThatAcceptIt(): void
    {
        $sql = "CREATE TABLE t (`flag` boolean NOT NULL);\n"
            . 'INSERT INTO t (`flag`) VALUES (1);';

        $this->assertStringContainsString('VALUES (1);', (new Converter('mysql', 'sqlite'))->convert($sql));
    }

    public function testZeroDateBecomesNullWithAWarning(): void
    {
        $sql = "CREATE TABLE t (`d` date);\n"
            . "INSERT INTO t (`d`) VALUES ('0000-00-00');";

        $converter = new Converter('mysql', 'pgsql');
        $out       = $converter->convert($sql);

        $this->assertStringContainsString('VALUES (NULL);', $out);
        $this->assertStringContainsString('Zero date', (string) $converter->report()->warnings()[0]);
    }

    public function testNullAndKeywordsPassThrough(): void
    {
        $sql = "INSERT INTO t (a, b) VALUES (NULL, CURRENT_TIMESTAMP);";

        $this->assertSame(
            'INSERT INTO "t" ("a", "b") VALUES (NULL, CURRENT_TIMESTAMP);',
            (new Converter('mysql', 'pgsql'))->convert($sql)
        );
    }

    public function testSemicolonInsideAValueDoesNotSplitTheStatement(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('a;b');";

        $this->assertSame(
            "INSERT INTO \"t\" (\"a\") VALUES ('a;b');",
            (new Converter('mysql', 'pgsql'))->convert($sql)
        );
    }

    // -----------------------------------------------------------------------
    // Default expressions
    // -----------------------------------------------------------------------

    /**
     * MariaDB writes `current_timestamp()` with parentheses, which SQLite
     * rejects outright. Found by converting a real mysqldump.
     *
     * @dataProvider nowFunctionProvider
     */
    public function testNowFunctionsNormaliseToTheStandardKeyword(string $default, string $expected): void
    {
        $sql = "CREATE TABLE t (`c` timestamp NOT NULL DEFAULT {$default});";

        $this->assertStringContainsString(
            "DEFAULT {$expected}",
            (new Converter('mysql', 'sqlite'))->convert($sql)
        );
    }

    /** @return array<string,array{string,string}> */
    public static function nowFunctionProvider(): array
    {
        return [
            'mariadb parens'   => ['current_timestamp()', 'CURRENT_TIMESTAMP'],
            'bare keyword'     => ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP'],
            'now()'            => ['now()', 'CURRENT_TIMESTAMP'],
            'with precision'   => ['current_timestamp(6)', 'CURRENT_TIMESTAMP'],
            'sqlsrv getdate()' => ['getdate()', 'CURRENT_TIMESTAMP'],
            'curdate()'        => ['curdate()', 'CURRENT_DATE'],
        ];
    }

    public function testNormalisedTimestampDefaultActuallyExecutes(): void
    {
        $this->requireSqlite();

        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $sql = 'CREATE TABLE t (`id` int NOT NULL, `created_at` timestamp NOT NULL DEFAULT current_timestamp());';

        foreach ((new Converter('mysql', 'sqlite'))->stream($sql) as $statement) {
            $pdo->exec($statement);
        }

        $pdo->exec('INSERT INTO t (id) VALUES (1)');

        $this->assertNotEmpty($pdo->query('SELECT created_at FROM t')->fetchColumn());
    }

    public function testPostgresSequenceDefaultIsDropped(): void
    {
        // The target declares its own auto-increment; carrying nextval() across
        // would reference a sequence that does not exist there.
        $sql = <<<'SQL'
        CREATE TABLE "t" (
            "id" integer DEFAULT nextval('t_id_seq'::regclass) NOT NULL
        );
        SQL;

        $out = (new Converter('pgsql', 'mysql'))->convert($sql);

        $this->assertStringNotContainsString('nextval', $out);
    }

    public function testUnknownFunctionDefaultIsCarriedThroughWithAWarning(): void
    {
        $converter = new Converter('mysql', 'pgsql');
        $out       = $converter->convert('CREATE TABLE t (`c` char(36) DEFAULT uuid());');

        $this->assertStringContainsString('DEFAULT uuid()', $out);
        $this->assertNotEmpty($converter->report()->warningsOfLevel(Warning::LEVEL_LOSSY));
    }

    // -----------------------------------------------------------------------
    // Database-level statements
    // -----------------------------------------------------------------------

    /**
     * mysqldump --databases emits CREATE DATABASE and USE. Passing those
     * through breaks execution: SQLite has no such statements at all, and the
     * target database is already chosen by the connection.
     *
     * @dataProvider databaseStatementProvider
     */
    public function testDatabaseLevelStatementsAreDropped(string $sql): void
    {
        $converter = new Converter('mysql', 'sqlite');
        $out       = $converter->convert($sql . "\nCREATE TABLE t (a int);");

        $this->assertStringNotContainsString('DATABASE', strtoupper($out));
        $this->assertStringNotContainsString('USE ', strtoupper($out));
        $this->assertStringContainsString('CREATE TABLE "t"', $out);
        $this->assertNotEmpty($converter->report()->warningsOfLevel(Warning::LEVEL_SKIPPED));
    }

    /** @return array<string,array{string}> */
    public static function databaseStatementProvider(): array
    {
        return [
            'create database'  => ['CREATE DATABASE `app`;'],
            'create if exists' => ['CREATE DATABASE /*!32312 IF NOT EXISTS*/ `app`;'],
            'use'              => ['USE `app`;'],
            'drop database'    => ['DROP DATABASE `app`;'],
            'create schema'    => ['CREATE SCHEMA `app`;'],
        ];
    }

    // -----------------------------------------------------------------------
    // Warnings and strictness
    // -----------------------------------------------------------------------

    public function testSessionStatementsAreDropped(): void
    {
        $converter = new Converter('mysql', 'pgsql');
        $out       = $converter->convert("SET NAMES utf8mb4;\nCREATE TABLE t (a int);");

        $this->assertStringNotContainsString('SET NAMES', $out);
        $this->assertNotEmpty($converter->report()->warningsOfLevel(Warning::LEVEL_SKIPPED));
    }

    /**
     * mysqldump brackets every table in LOCK/UNLOCK. Passing those through
     * verbatim put backticked MySQL in front of PostgreSQL and aborted apply()
     * on the first table.
     *
     * @dataProvider maintenanceStatementProvider
     */
    public function testMaintenanceStatementsAreDropped(string $sql): void
    {
        $converter = new Converter('mysql', 'pgsql');
        $out       = $converter->convert($sql . "
CREATE TABLE `t` (`a` int);");

        $this->assertStringNotContainsString('`', $out, 'MySQL quoting leaked into the output');
        $this->assertStringNotContainsString('LOCK', strtoupper($out));
        $this->assertStringNotContainsString('FLUSH', strtoupper($out));

        // The rest of the stream must survive the drop.
        $this->assertStringContainsString('CREATE TABLE "t"', $out);
        $this->assertNotEmpty($converter->report()->warningsOfLevel(Warning::LEVEL_SKIPPED));
        $this->assertEmpty($converter->report()->warningsOfLevel(Warning::LEVEL_PASSTHROUGH));
    }

    /** @return array<string,array{string}> */
    public static function maintenanceStatementProvider(): array
    {
        return [
            'lock write'     => ['LOCK TABLES `t` WRITE;'],
            'lock read'      => ['LOCK TABLES `t` READ;'],
            'unlock'         => ['UNLOCK TABLES;'],
            'flush'          => ['FLUSH TABLES;'],
            'analyze'        => ['ANALYZE TABLE `t`;'],
            'optimize'       => ['OPTIMIZE TABLE `t`;'],
            'repair'         => ['REPAIR TABLE `t`;'],
            'checksum'       => ['CHECKSUM TABLE `t`;'],
        ];
    }

    /** The exact LOCK/INSERT/UNLOCK sandwich mysqldump writes per table. */
    public function testMysqldumpLockSandwichRoundTripsToSqlite(): void
    {
        $this->requireSqlite();

        $dump = <<<'SQL'
--
-- Dumping data for table `mod_invoicedata`
--

LOCK TABLES `mod_invoicedata` WRITE;
/*!40000 ALTER TABLE `mod_invoicedata` DISABLE KEYS */;
INSERT INTO `mod_invoicedata` (`id`, `label`) VALUES (1,'first'),(2,'second');
/*!40000 ALTER TABLE `mod_invoicedata` ENABLE KEYS */;
UNLOCK TABLES;
SQL;

        $converter = new Converter('mysql', 'sqlite');
        $out       = $converter->convert(
            "CREATE TABLE `mod_invoicedata` (`id` int NOT NULL, `label` varchar(50));
" . $dump
        );

        $this->assertStringNotContainsString('`', $out);
        $this->assertStringNotContainsString('LOCK TABLES', strtoupper($out));

        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach (explode(";
", trim($out)) as $sql) {
            if (trim($sql, " 
;") !== '') {
                $pdo->exec($sql);
            }
        }

        $rows = $pdo->query('SELECT id, label FROM mod_invoicedata ORDER BY id')
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame(
            [['id' => 1, 'label' => 'first'], ['id' => 2, 'label' => 'second']],
            $rows
        );
    }

    // -----------------------------------------------------------------------
    // Zero dates in column DEFAULTs
    //
    // '0000-00-00' is a legal MySQL default that PostgreSQL rejects outright.
    // NULL is not a substitute because these columns are typically NOT NULL,
    // so the clause is dropped and the column keeps its type.
    // -----------------------------------------------------------------------

    /** @dataProvider zeroDateTargetProvider */
    public function testZeroDateDefaultIsDropped(string $to): void
    {
        $converter = new Converter('mysql', $to);
        $out       = $converter->convert(
            "CREATE TABLE `t` ("
            . "`date` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00', "
            . "`d2` date NOT NULL DEFAULT '0000-00-00');"
        );

        $this->assertStringNotContainsString('0000-00-00', $out);

        // Dropped, not silently turned into a fabricated value. Blueprint's
        // timestamp() bakes in a CURRENT_TIMESTAMP that has to be cleared too.
        $this->assertStringNotContainsString('CURRENT_TIMESTAMP', $out);
        $this->assertStringNotContainsString('DEFAULT', strtoupper($out));

        // The columns survive, but NOT NULL goes with the default: a zero date
        // means "absent", and the values translate to NULL.
        $this->assertStringNotContainsString('NOT NULL', $out);
        $this->assertNotEmpty($converter->report()->warningsOfLevel(Warning::LEVEL_LOSSY));
    }

    /** @return array<string,array{string}> */
    public static function zeroDateTargetProvider(): array
    {
        return [
            'pgsql'  => ['pgsql'],
            'sqlite' => ['sqlite'],
            'sqlsrv' => ['sqlsrv'],
        ];
    }

    public function testZeroDateDefaultSurvivesMySqlToMySql(): void
    {
        $converter = new Converter('mysql', 'mysql');
        $out       = $converter->convert(
            "CREATE TABLE `t` (`date` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00');"
        );

        $this->assertStringContainsString("'0000-00-00 00:00:00'", $out);
        $this->assertEmpty($converter->report()->warningsOfLevel(Warning::LEVEL_LOSSY));
    }

    public function testGenuineDefaultsAreNotCollateralDamage(): void
    {
        $converter = new Converter('mysql', 'pgsql');
        $out       = $converter->convert(
            "CREATE TABLE `t` ("
            . "`ts` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, "
            . "`n` int NOT NULL DEFAULT 7, "
            . "`s` varchar(10) NOT NULL DEFAULT 'x');"
        );

        $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $out);
        $this->assertStringContainsString('DEFAULT 7', $out);
        $this->assertStringContainsString("DEFAULT 'x'", $out);
    }

    public function testZeroDateTableActuallyExecutes(): void
    {
        $this->requireSqlite();

        $converter = new Converter('mysql', 'sqlite');
        $out       = $converter->convert(
            "CREATE TABLE `t` (`id` int NOT NULL, `date` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00');"
        );

        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec($out);

        $pdo->exec("INSERT INTO t (id, date) VALUES (1, '2024-01-01 00:00:00')");
        $this->assertSame('2024-01-01 00:00:00', $pdo->query('SELECT date FROM t')->fetchColumn());

        // The zero-date row must land too — that is the whole point of relaxing
        // NOT NULL, and it is where apply() failed against PostgreSQL.
        $rows = (new Converter('mysql', 'sqlite'))->convert(
            "INSERT INTO `t` (`id`, `date`) VALUES (2, '0000-00-00 00:00:00');"
        );
        $pdo->exec($rows);

        $this->assertNull($pdo->query('SELECT date FROM t WHERE id = 2')->fetchColumn());
    }

    public function testOnlyZeroDateColumnsLoseNotNull(): void
    {
        $converter = new Converter('mysql', 'pgsql');
        $out       = $converter->convert(
            "CREATE TABLE `t` ("
            . "`zero` datetime NOT NULL DEFAULT '0000-00-00 00:00:00', "
            . "`plain` datetime NOT NULL, "
            . "`named` varchar(10) NOT NULL DEFAULT 'x');"
        );

        $this->assertStringContainsString('"zero" TIMESTAMP NULL', $out);
        $this->assertStringContainsString('"plain" TIMESTAMP NOT NULL', $out);
        $this->assertStringContainsString('"named" VARCHAR(10) NOT NULL', $out);
    }

    public function testUnsupportedStatementIsPassedThroughNotDropped(): void
    {
        $converter = new Converter('mysql', 'pgsql');
        $out       = $converter->convert('CREATE VIEW v AS SELECT 1;');

        $this->assertStringContainsString('CREATE VIEW v AS SELECT 1;', $out);
        $this->assertNotEmpty($converter->report()->warningsOfLevel(Warning::LEVEL_PASSTHROUGH));
    }

    public function testStrictModeThrowsOnTheFirstWarning(): void
    {
        $converter = new Converter('mysql', 'pgsql', strict: true);

        $this->expectException(ConverterException::class);
        $converter->convert('CREATE VIEW v AS SELECT 1;');
    }

    public function testReportCountsStatementsByKind(): void
    {
        $converter = new Converter('mysql', 'pgsql');
        $converter->convert(
            "CREATE TABLE t (a int);\n"
            . "INSERT INTO t (a) VALUES (1);\n"
            . "INSERT INTO t (a) VALUES (2);\n"
            . 'DROP TABLE t;'
        );

        $this->assertSame(
            ['create_table' => 1, 'insert' => 2, 'drop_table' => 1],
            $converter->report()->counts()
        );
        $this->assertSame(4, $converter->report()->statementCount());
    }

    public function testResetClearsState(): void
    {
        $converter = new Converter('mysql', 'pgsql');
        $converter->convert('CREATE VIEW v AS SELECT 1;');
        $this->assertTrue($converter->report()->hasWarnings());

        $converter->reset();

        $this->assertFalse($converter->report()->hasWarnings());
        $this->assertSame(0, $converter->report()->statementCount());
    }

    // -----------------------------------------------------------------------
    // Streaming and files
    // -----------------------------------------------------------------------

    public function testStreamYieldsOneStatementAtATime(): void
    {
        $converter = new Converter('mysql', 'pgsql');

        $statements = iterator_to_array(
            $converter->stream("CREATE TABLE t (a int);\nINSERT INTO t (a) VALUES (1),(2);"),
            false
        );

        $this->assertCount(3, $statements);
        $this->assertStringStartsWith('CREATE TABLE', $statements[0]);
        $this->assertStringStartsWith('INSERT INTO', $statements[1]);
    }

    public function testConvertFileWritesEveryStatement(): void
    {
        $source      = tempnam(sys_get_temp_dir(), 'laika-src-');
        $destination = tempnam(sys_get_temp_dir(), 'laika-dst-');

        file_put_contents($source, "CREATE TABLE t (a int);\nINSERT INTO t (a) VALUES (1),(2),(3);");

        try {
            $written = (new Converter('mysql', 'pgsql'))->convertFile($source, $destination);

            $this->assertSame(4, $written);

            $output = file_get_contents($destination);
            $this->assertStringContainsString('CREATE TABLE "t"', $output);
            $this->assertSame(3, substr_count($output, 'INSERT INTO "t"'));
        } finally {
            @unlink($source);
            @unlink($destination);
        }
    }

    // -----------------------------------------------------------------------
    // The real proof: converted SQL must execute and round-trip the data
    // -----------------------------------------------------------------------

    public function testMySqlDumpConvertedToSqliteExecutesAndKeepsItsData(): void
    {
        $this->requireSqlite();

        $dump = <<<'SQL'
        -- MySQL dump 10.13
        /*!40101 SET @saved_cs_client = @@character_set_client */;
        SET NAMES utf8mb4;

        DROP TABLE IF EXISTS `authors`;
        CREATE TABLE `authors` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `name` varchar(120) NOT NULL,
          `active` tinyint(1) NOT NULL DEFAULT 1,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        INSERT INTO `authors` (`id`, `name`, `active`) VALUES
         (1,'Ada Lovelace',1),
         (2,'O\'Brien; the second',0),
         (3,'quo''ted',1);

        DROP TABLE IF EXISTS `posts`;
        CREATE TABLE `posts` (
          `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `author_id` int(10) unsigned NOT NULL,
          `title` varchar(190) NOT NULL,
          `body` longtext,
          `score` double DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_author` (`author_id`),
          CONSTRAINT `fk_author` FOREIGN KEY (`author_id`) REFERENCES `authors` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        INSERT INTO `posts` (`id`, `author_id`, `title`, `body`, `score`) VALUES
         (1,1,'First; post','Body with a ; semicolon',9.5),
         (2,2,'Second','It\'s fine',NULL);
        SQL;

        $converter = new Converter('mysql', 'sqlite');

        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        foreach ($converter->stream($dump) as $sql) {
            $pdo->exec($sql);
        }

        $authors = $pdo->query('SELECT id, name, active FROM authors ORDER BY id')
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame(
            [
                ['id' => 1, 'name' => 'Ada Lovelace', 'active' => 1],
                ['id' => 2, 'name' => "O'Brien; the second", 'active' => 0],
                ['id' => 3, 'name' => "quo'ted", 'active' => 1],
            ],
            $authors
        );

        $posts = $pdo->query('SELECT id, author_id, title, body, score FROM posts ORDER BY id')
            ->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertSame('First; post', $posts[0]['title']);
        $this->assertSame('Body with a ; semicolon', $posts[0]['body']);
        $this->assertSame(9.5, $posts[0]['score']);
        $this->assertSame("It's fine", $posts[1]['body']);
        $this->assertNull($posts[1]['score']);

        // The index survived as a separate CREATE INDEX statement.
        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'posts'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('idx_posts_author', $indexes);

        // And the foreign key is live.
        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO posts (id, author_id, title) VALUES (99, 404, 'orphan')");
    }

    public function testApplyExecutesAgainstARegisteredConnection(): void
    {
        $this->requireSqlite();

        Connection::add(['driver' => 'sqlite', 'database' => ':memory:'], 'target');

        $dump = "CREATE TABLE `t` (`id` int NOT NULL AUTO_INCREMENT, `v` varchar(20), PRIMARY KEY (`id`));\n"
            . "INSERT INTO `t` (`id`, `v`) VALUES (1,'a'),(2,'b');";

        $executed = (new Converter('mysql', 'sqlite'))->apply($dump);

        $this->assertSame(3, $executed);

        $rows = Connection::get('target')->query('SELECT v FROM t ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['a', 'b'], $rows);
    }

    public function testApplyRefusesAMismatchedConnection(): void
    {
        Connection::add(['driver' => 'mysql', 'host' => 'db', 'database' => 'x'], 'wrong');

        $this->expectException(ConverterException::class);
        $this->expectExceptionMessageMatches('/targets \[pgsql\]/');

        (new Converter('mysql', 'pgsql'))->apply('CREATE TABLE t (a int);');
    }

    // -----------------------------------------------------------------------
    // Type lexicon
    // -----------------------------------------------------------------------

    /** @dataProvider nativeTypeProvider */
    public function testTypeLexiconReadsNativeTypes(string $dialect, string $native, ?string $expected): void
    {
        $this->assertSame($expected, (new TypeLexicon($dialect))->toCanonical($native));
    }

    /** @return array<string,array{string,string,?string}> */
    public static function nativeTypeProvider(): array
    {
        return [
            'mysql int'          => ['mysql', 'int(10) unsigned', 'integer'],
            'mysql varchar'      => ['mysql', 'varchar(190)', 'string'],
            'mysql longtext'     => ['mysql', 'LONGTEXT', 'longText'],
            'mysql enum'         => ['mysql', "enum('a','b')", 'enum'],
            'mysql decimal'      => ['mysql', 'decimal(8,2)', 'decimal'],
            'pg serial'          => ['pgsql', 'serial', 'id'],
            'pg bigserial'       => ['pgsql', 'bigserial', 'bigId'],
            'pg varying'         => ['pgsql', 'character varying(190)', 'string'],
            'pg double'          => ['pgsql', 'double precision', 'double'],
            'pg timestamptz'     => ['pgsql', 'timestamp with time zone', 'timestamp'],
            'pg jsonb'           => ['pgsql', 'jsonb', 'json'],
            'pg bytea'           => ['pgsql', 'bytea', 'blob'],
            'pg bit is not bool' => ['pgsql', 'bit(8)', 'string'],
            'sqlsrv nvarchar'    => ['sqlsrv', 'nvarchar(max)', 'string'],
            'sqlsrv rowversion'  => ['sqlsrv', 'timestamp', 'binary'],
            'unknown'            => ['mysql', 'geometry', null],
        ];
    }

    /**
     * The lexicon is the inverse of the grammars' type tables. If a canonical
     * type emits SQL the lexicon cannot read back, a round-trip conversion
     * silently degrades it.
     *
     * @dataProvider roundTripProvider
     */
    public function testCanonicalTypesSurviveARoundTrip(string $canonical, string $columnSql): void
    {
        $sql = "CREATE TABLE t ({$columnSql});";

        // mysql -> mysql must be a no-op in canonical terms.
        $out = (new Converter('mysql', 'mysql'))->convert($sql);

        $this->assertStringContainsString('`c`', $out, "Column lost for canonical type [{$canonical}]");

        $again = (new Converter('mysql', 'mysql'))->convert($out);

        $this->assertSame($out, $again, "Type [{$canonical}] is not stable across a round trip");
    }

    /** @return array<string,array{string,string}> */
    public static function roundTripProvider(): array
    {
        return [
            'integer'    => ['integer', '`c` INT NOT NULL'],
            'bigInteger' => ['bigInteger', '`c` BIGINT NOT NULL'],
            'string'     => ['string', '`c` VARCHAR(190) NOT NULL'],
            'char'       => ['char', '`c` CHAR(36) NOT NULL'],
            'text'       => ['text', '`c` TEXT NULL'],
            'longText'   => ['longText', '`c` LONGTEXT NULL'],
            'decimal'    => ['decimal', '`c` DECIMAL(8,2) NOT NULL'],
            'boolean'    => ['boolean', '`c` TINYINT(1) NOT NULL'],
            'date'       => ['date', '`c` DATE NULL'],
            'dateTime'   => ['dateTime', '`c` DATETIME NULL'],
            'json'       => ['json', '`c` JSON NULL'],
            'blob'       => ['blob', '`c` BLOB NULL'],
            'enum'       => ['enum', "`c` ENUM('a', 'b') NOT NULL"],
        ];
    }
}
