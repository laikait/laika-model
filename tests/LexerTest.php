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
use Laika\Model\Converter\Statement;
use Laika\Model\Converter\StatementReader;

/**
 * Statement-splitting tests.
 *
 * Everything here is a case where splitting on ";" gives the wrong answer.
 */
class LexerTest extends TestCase
{
    /**
     * @return string[] The SQL of each statement found.
     */
    private function split(string $sql, string $dialect = 'mysql', int $chunkSize = 8192): array
    {
        $reader = new StatementReader($dialect, $chunkSize);

        return array_map(
            fn(Statement $s): string => $s->sql,
            iterator_to_array($reader->read($sql), false)
        );
    }

    // -----------------------------------------------------------------------
    // The basics
    // -----------------------------------------------------------------------

    public function testSplitsOnSemicolons(): void
    {
        $this->assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->split('SELECT 1; SELECT 2;')
        );
    }

    public function testTrailingStatementWithoutDelimiterIsEmitted(): void
    {
        $this->assertSame(['SELECT 1', 'SELECT 2'], $this->split('SELECT 1; SELECT 2'));
    }

    public function testEmptyStatementsAreSkipped(): void
    {
        $this->assertSame(['SELECT 1', 'SELECT 2'], $this->split(';;SELECT 1;;; SELECT 2;;'));
    }

    public function testBlankInputYieldsNothing(): void
    {
        $this->assertSame([], $this->split("   \n\n  \t "));
        $this->assertSame([], $this->split(''));
    }

    // -----------------------------------------------------------------------
    // Semicolons that must NOT split
    // -----------------------------------------------------------------------

    public function testSemicolonInsideStringLiteral(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES ('a;b')"],
            $this->split("INSERT INTO t VALUES ('a;b');")
        );
    }

    public function testSemicolonInsideBacktickIdentifier(): void
    {
        $this->assertSame(
            ['SELECT `we;ird` FROM t'],
            $this->split('SELECT `we;ird` FROM t;')
        );
    }

    public function testSemicolonInsideLineComment(): void
    {
        $this->assertSame(
            ['SELECT 1 -- a ; comment', 'SELECT 2'],
            $this->split("SELECT 1 -- a ; comment\n; SELECT 2;")
        );
    }

    public function testSemicolonInsideHashComment(): void
    {
        $this->assertSame(
            ['SELECT 1 # a ; comment', 'SELECT 2'],
            $this->split("SELECT 1 # a ; comment\n; SELECT 2;")
        );
    }

    public function testSemicolonInsideBlockComment(): void
    {
        $this->assertSame(
            ['SELECT /* a ; comment */ 1', 'SELECT 2'],
            $this->split('SELECT /* a ; comment */ 1; SELECT 2;')
        );
    }

    public function testDoubledQuoteIsALiteralNotATerminator(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES ('it''s; fine')"],
            $this->split("INSERT INTO t VALUES ('it''s; fine');")
        );
    }

    public function testBackslashEscapedQuoteInMySql(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES ('it\\'s; fine')"],
            $this->split("INSERT INTO t VALUES ('it\\'s; fine');", 'mysql')
        );
    }

    public function testTrailingBackslashBeforeClosingQuote(): void
    {
        // '...\\' ends the string: the backslash escapes a backslash.
        $this->assertSame(
            ["INSERT INTO t VALUES ('back\\\\')", 'SELECT 2'],
            $this->split("INSERT INTO t VALUES ('back\\\\'); SELECT 2;", 'mysql')
        );
    }

    /**
     * PostgreSQL treats a backslash literally in a standard string, so
     * 'a\' is a COMPLETE string. Applying MySQL's rule here would swallow
     * the rest of the file.
     */
    public function testPostgresDoesNotTreatBackslashAsAnEscape(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES ('a\\')", 'SELECT 2'],
            $this->split("INSERT INTO t VALUES ('a\\'); SELECT 2;", 'pgsql')
        );
    }

    public function testPostgresEscapeStringHonoursBackslash(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES (E'a\\'b; c')"],
            $this->split("INSERT INTO t VALUES (E'a\\'b; c');", 'pgsql')
        );
    }

    public function testArithmeticDoubleMinusIsNotAComment(): void
    {
        // "--" is only a comment when whitespace follows.
        $this->assertSame(['SELECT 5--3', 'SELECT 2'], $this->split('SELECT 5--3; SELECT 2;'));
    }

    public function testSqlSrvBracketIdentifier(): void
    {
        $this->assertSame(
            ['SELECT [we;ird] FROM [t]'],
            $this->split('SELECT [we;ird] FROM [t];', 'sqlsrv')
        );
    }

    // -----------------------------------------------------------------------
    // mysqldump-specific syntax
    // -----------------------------------------------------------------------

    public function testMySqlConditionalCommentIsOneStatement(): void
    {
        $this->assertSame(
            ['/*!40101 SET @saved_cs_client = @@character_set_client */'],
            $this->split('/*!40101 SET @saved_cs_client = @@character_set_client */;')
        );
    }

    public function testDelimiterDirectiveChangesTheTerminator(): void
    {
        $sql = "DELIMITER $$\n"
            . "CREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END$$\n"
            . "DELIMITER ;\n"
            . "SELECT 3;";

        $this->assertSame(
            [
                'CREATE PROCEDURE p() BEGIN SELECT 1; SELECT 2; END',
                'SELECT 3',
            ],
            $this->split($sql)
        );
    }

    public function testDollarQuotedBodyInPostgres(): void
    {
        $sql = "CREATE FUNCTION f() RETURNS int AS \$\$ BEGIN RETURN 1; END; \$\$ LANGUAGE plpgsql;\nSELECT 2;";

        $this->assertSame(
            [
                "CREATE FUNCTION f() RETURNS int AS \$\$ BEGIN RETURN 1; END; \$\$ LANGUAGE plpgsql",
                'SELECT 2',
            ],
            $this->split($sql, 'pgsql')
        );
    }

    public function testTaggedDollarQuoteInPostgres(): void
    {
        $sql = "SELECT \$body\$ has ; inside \$body\$; SELECT 2;";

        $this->assertSame(
            ["SELECT \$body\$ has ; inside \$body\$", 'SELECT 2'],
            $this->split($sql, 'pgsql')
        );
    }

    // -----------------------------------------------------------------------
    // Chunk boundaries — the same input must split identically at any size
    // -----------------------------------------------------------------------

    /** @dataProvider chunkSizeProvider */
    public function testSplittingIsIndependentOfChunkSize(int $chunkSize): void
    {
        $sql = "-- banner ; line\n"
            . "CREATE TABLE `t` (`a;b` INT);\n"
            . "/*!40101 SET x = 1 */;\n"
            . "INSERT INTO t VALUES ('semi;colon', 'quo''ted', 'back\\\\slash');\n"
            . "SELECT 5--3;\n"
            . "/* block ; comment */ SELECT 1;";

        $expected = $this->split($sql, 'mysql', 65536);

        $this->assertSame($expected, $this->split($sql, 'mysql', $chunkSize));
    }

    /** @return array<string,array{int}> */
    public static function chunkSizeProvider(): array
    {
        return [
            '1 byte'   => [1],
            '2 bytes'  => [2],
            '3 bytes'  => [3],
            '7 bytes'  => [7],
            '16 bytes' => [16],
            '64 bytes' => [64],
        ];
    }

    /**
     * A one-byte chunk size puts a boundary between every character, which is
     * where the "is this quote doubled?" and "does this comment start?"
     * lookaheads break if they are not handled.
     */
    public function testOneBytePerChunkStillSplitsCorrectly(): void
    {
        $this->assertSame(
            ["INSERT INTO t VALUES ('it''s; ok')", 'SELECT 2'],
            $this->split("INSERT INTO t VALUES ('it''s; ok'); SELECT 2;", 'mysql', 1)
        );
    }

    // -----------------------------------------------------------------------
    // Statement classification
    // -----------------------------------------------------------------------

    /** @dataProvider kindProvider */
    public function testStatementKind(string $sql, string $expected): void
    {
        $reader     = new StatementReader('mysql');
        $statements = iterator_to_array($reader->read($sql), false);

        $this->assertCount(1, $statements);
        $this->assertSame($expected, $statements[0]->kind());
    }

    /** @return array<string,array{string,string}> */
    public static function kindProvider(): array
    {
        return [
            'create table'         => ['CREATE TABLE t (a INT);', Statement::KIND_CREATE_TABLE],
            'create temp table'    => ['CREATE TEMPORARY TABLE t (a INT);', Statement::KIND_CREATE_TABLE],
            'create index'         => ['CREATE INDEX i ON t (a);', Statement::KIND_CREATE_INDEX],
            'create unique index'  => ['CREATE UNIQUE INDEX i ON t (a);', Statement::KIND_CREATE_INDEX],
            'insert'               => ["INSERT INTO t VALUES (1);", Statement::KIND_INSERT],
            'alter table'          => ['ALTER TABLE t ADD COLUMN b INT;', Statement::KIND_ALTER_TABLE],
            'drop table'           => ['DROP TABLE t;', Statement::KIND_DROP_TABLE],
            'set'                  => ['SET foreign_key_checks = 0;', Statement::KIND_SET],
            'commit'               => ['COMMIT;', Statement::KIND_TRANSACTION],
            'lock tables'          => ['LOCK TABLES `t` WRITE;', Statement::KIND_MAINTENANCE],
            'lock tables read'     => ['LOCK TABLES `t` READ;', Statement::KIND_MAINTENANCE],
            'unlock tables'        => ['UNLOCK TABLES;', Statement::KIND_MAINTENANCE],
            'flush tables'         => ['FLUSH TABLES;', Statement::KIND_MAINTENANCE],
            'flush local tables'   => ['FLUSH LOCAL TABLES;', Statement::KIND_MAINTENANCE],
            'analyze table'        => ['ANALYZE TABLE `t`;', Statement::KIND_MAINTENANCE],
            'optimize table'       => ['OPTIMIZE TABLE `t`;', Statement::KIND_MAINTENANCE],
            'repair table'         => ['REPAIR TABLE `t`;', Statement::KIND_MAINTENANCE],
            'checksum table'       => ['CHECKSUM TABLE `t`;', Statement::KIND_MAINTENANCE],
            'select'               => ['SELECT 1;', Statement::KIND_OTHER],
            // Guard the deliberate exclusion: a CHECK constraint is not
            // maintenance, and must not be swallowed by the LOCK/FLUSH arm.
            'check constraint'     => ['ALTER TABLE t ADD CHECK (a > 0);', Statement::KIND_ALTER_TABLE],
        ];
    }

    public function testKindIgnoresLeadingComments(): void
    {
        $sql = "-- Table structure for table `users`\n"
            . "-- ------------------------------\n"
            . "CREATE TABLE `users` (`id` INT);";

        $statements = iterator_to_array((new StatementReader('mysql'))->read($sql), false);

        $this->assertSame(Statement::KIND_CREATE_TABLE, $statements[0]->kind());
    }

    public function testCommentOnlyStatementIsClassifiedAsComment(): void
    {
        $statements = iterator_to_array((new StatementReader('mysql'))->read("-- just a note\n"), false);

        $this->assertCount(1, $statements);
        $this->assertSame(Statement::KIND_COMMENT, $statements[0]->kind());
    }

    // -----------------------------------------------------------------------
    // Provenance
    // -----------------------------------------------------------------------

    public function testOrdinalAndOffsetAreTracked(): void
    {
        $statements = iterator_to_array(
            (new StatementReader('mysql'))->read('SELECT 1; SELECT 22; SELECT 333;'),
            false
        );

        $this->assertSame([1, 2, 3], array_map(fn(Statement $s) => $s->ordinal, $statements));
        $this->assertSame([0, 9, 20], array_map(fn(Statement $s) => $s->offset, $statements));
    }

    // -----------------------------------------------------------------------
    // Input sources
    // -----------------------------------------------------------------------

    public function testReadsFromAFilePath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'laika-sql-');
        file_put_contents($path, "SELECT 1;\nSELECT 2;\n");

        try {
            $reader = new StatementReader('mysql');
            $sql    = array_map(fn(Statement $s) => $s->sql, iterator_to_array($reader->read($path), false));

            $this->assertSame(['SELECT 1', 'SELECT 2'], $sql);
        } finally {
            @unlink($path);
        }
    }

    public function testReadsFromAResource(): void
    {
        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, 'SELECT 1; SELECT 2;');
        rewind($handle);

        try {
            $reader = new StatementReader('mysql');
            $sql    = array_map(fn(Statement $s) => $s->sql, iterator_to_array($reader->read($handle), false));

            $this->assertSame(['SELECT 1', 'SELECT 2'], $sql);
        } finally {
            fclose($handle);
        }
    }

    public function testSqlTextIsNotMistakenForAPath(): void
    {
        // Multi-line SQL must never be probed as a filename.
        $this->assertSame(['SELECT 1', 'SELECT 2'], $this->split("SELECT 1;\nSELECT 2;"));
    }

    /**
     * The constant-memory promise: a dump much larger than the buffer must
     * stream without the whole file ever being resident.
     */
    public function testLargeInputStreamsWithoutGrowingMemory(): void
    {
        $rows = 20000;
        $path = tempnam(sys_get_temp_dir(), 'laika-dump-');

        $handle = fopen($path, 'wb');
        fwrite($handle, "CREATE TABLE t (id INT, body TEXT);\n");
        for ($i = 0; $i < $rows; $i++) {
            // Each row carries a semicolon and a doubled quote, so the scanner
            // is doing real work rather than skipping plain text.
            fwrite($handle, "INSERT INTO t VALUES ({$i}, 'a;b''c " . str_repeat('x', 60) . "');\n");
        }
        fclose($handle);

        try {
            $fileSize = filesize($path);
            $this->assertGreaterThan(1_000_000, $fileSize, 'Fixture should be comfortably over 1 MB');

            $before = memory_get_usage(true);
            $count  = 0;

            foreach ((new StatementReader('mysql'))->read($path) as $statement) {
                $count++;
            }

            $growth = memory_get_usage(true) - $before;

            $this->assertSame($rows + 1, $count);
            $this->assertLessThan(
                $fileSize / 4,
                $growth,
                'Memory grew with the file — the reader is not streaming'
            );
        } finally {
            @unlink($path);
        }
    }
}
