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

namespace Laika\Model\Converter;

use Laika\Model\Connection;
use Laika\Model\Converter\Parsers\CreateTableParser;
use Laika\Model\Converter\Parsers\IndexParser;
use Laika\Model\Converter\Parsers\InsertParser;
use Laika\Model\Drivers\DriverFactory;
use Laika\Model\Exceptions\ConverterException;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Grammars\Grammar;
use Laika\Model\Schema\Grammars\MySqlGrammar;
use Laika\Model\Schema\Grammars\PgSqlGrammar;
use Laika\Model\Schema\Grammars\SqliteGrammar;
use Laika\Model\Schema\Grammars\SqlSrvGrammar;

/**
 * Translate SQL from one driver dialect to another.
 *
 * Handles DDL (CREATE TABLE, CREATE INDEX, DROP TABLE) and INSERT data — what a
 * mysqldump or pg_dump backup actually contains. Views, triggers and stored
 * routines are out of scope: they are passed through unchanged with a warning.
 *
 *   $converter = new Converter('mysql', 'pgsql');
 *
 *   echo $converter->convert('CREATE TABLE users (id INT AUTO_INCREMENT ...)');
 *
 *   foreach ($converter->stream('dump.sql') as $sql) {   // constant memory
 *       echo $sql, "\n";
 *   }
 *
 *   $converter->convertFile('mysql.sql', 'pgsql.sql');
 *   $converter->apply('mysql.sql', 'reporting');
 *
 *   print_r($converter->report()->warnings());
 */
final class Converter
{
    /** @var array<string,class-string<Grammar>> Canonical driver => grammar. */
    private const GRAMMARS = [
        'mysql'  => MySqlGrammar::class,
        'pgsql'  => PgSqlGrammar::class,
        'sqlite' => SqliteGrammar::class,
        'sqlsrv' => SqlSrvGrammar::class,
    ];

    private readonly string $from;
    private readonly string $to;

    private Grammar $grammar;
    private Report $report;
    private TypeLexicon $lexicon;
    private LiteralTranslator $literals;
    private CreateTableParser $createTableParser;
    private InsertParser $insertParser;
    private IndexParser $indexParser;

    /**
     * Canonical types of the columns of every table seen so far, keyed by
     * table then column. INSERT literal translation needs it: whether `0`
     * becomes FALSE depends on the column being a boolean.
     *
     * @var array<string,array<string,string>>
     */
    private array $schema = [];

    /**
     * @param string $from Source dialect. Aliases accepted ('mariadb', 'postgres', ...).
     * @param string $to   Target dialect.
     * @param bool   $strict Promote every warning to a ConverterException.
     */
    public function __construct(string $from, string $to, private readonly bool $strict = false)
    {
        $this->from = $this->canonical($from);
        $this->to   = $this->canonical($to);

        if (!isset(self::GRAMMARS[$this->to])) {
            throw new ConverterException(
                "No grammar for target driver [{$this->to}]. Supported targets: "
                . implode(', ', array_keys(self::GRAMMARS)) . '.'
            );
        }

        $this->reset();
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Convert a whole source and return it as one string.
     *
     * Convenient for a single statement or a small file. For anything large use
     * stream() or convertFile(), which never hold the whole output in memory.
     *
     * @param string|resource|\SplFileObject $source
     */
    public function convert(mixed $source): string
    {
        return implode("\n", iterator_to_array($this->stream($source), false));
    }

    /**
     * Stream converted statements, one at a time.
     *
     * @param string|resource|\SplFileObject $source
     * @return \Generator<int,string> Each yielded string is a complete statement.
     */
    public function stream(mixed $source): \Generator
    {
        $reader = new StatementReader($this->from);

        foreach ($reader->read($source) as $statement) {
            $this->report->count($statement->kind());

            foreach ($this->convertStatement($statement) as $sql) {
                yield $sql;
            }
        }
    }

    /**
     * Convert one file to another, streaming throughout.
     *
     * @return int Number of statements written.
     */
    public function convertFile(string $source, string $destination): int
    {
        $handle = fopen($destination, 'wb');

        if ($handle === false) {
            throw new ConverterException("Cannot open [{$destination}] for writing.");
        }

        $written = 0;

        try {
            foreach ($this->stream($source) as $sql) {
                fwrite($handle, $sql . "\n");
                $written++;
            }
        } finally {
            fclose($handle);
        }

        return $written;
    }

    /**
     * Convert and execute against a registered connection.
     *
     * Foreign key checks are disabled for the duration so the dump's table
     * order does not matter, and re-enabled even if a statement throws.
     *
     * @param string|resource|\SplFileObject $source
     * @param ?string $connection Connection name (default: the current default).
     *                            Its driver must match the converter's target —
     *                            resolving by dialect name instead would make two
     *                            connections to the same engine unreachable.
     * @throws ConverterException When $connection targets a different driver.
     * @return int Number of statements executed.
     */
    public function apply(mixed $source, ?string $connection = null): int
    {
        $name = $connection ?? Connection::getDefault();

        if (Connection::driver($name) !== $this->to) {
            throw new ConverterException(
                "Connection [{$name}] is a [" . Connection::driver($name) . '] connection, '
                . "but this converter targets [{$this->to}]."
            );
        }

        $pdo      = Connection::get($name);
        $executed = 0;

        $this->toggleForeignKeys($pdo, false);

        try {
            foreach ($this->stream($source) as $sql) {
                try {
                    $pdo->exec($sql);
                    $executed++;
                } catch (\PDOException $e) {
                    throw new ConverterException(
                        "Failed executing converted statement [{$sql}]: {$e->getMessage()}",
                        (int) $e->getCode(),
                        $e
                    );
                }
            }
        } finally {
            $this->toggleForeignKeys($pdo, true);
        }

        return $executed;
    }

    /** What happened during the most recent conversion. */
    public function report(): Report
    {
        return $this->report;
    }

    /** Clear all accumulated state so the instance can be reused. */
    public function reset(): void
    {
        $grammarClass = self::GRAMMARS[$this->to];

        $this->grammar  = new $grammarClass();
        $this->report   = new Report($this->strict);
        $this->lexicon  = new TypeLexicon($this->from);
        $this->literals = new LiteralTranslator($this->from, $this->to, $this->report);
        $this->schema   = [];

        $this->createTableParser = new CreateTableParser(
            $this->lexicon,
            new BlueprintBuilder($this->lexicon, $this->report, $this->to),
            $this->report
        );
        $this->insertParser = new InsertParser($this->report);
        $this->indexParser  = new IndexParser();
    }

    public function sourceDialect(): string
    {
        return $this->from;
    }

    public function targetDialect(): string
    {
        return $this->to;
    }

    // -----------------------------------------------------------------------
    // Statement dispatch
    // -----------------------------------------------------------------------

    /**
     * @return \Generator<int,string>
     */
    private function convertStatement(Statement $statement): \Generator
    {
        switch ($statement->kind()) {
            case Statement::KIND_CREATE_TABLE:
                yield from $this->convertCreateTable($statement);
                return;

            case Statement::KIND_CREATE_INDEX:
                yield from $this->convertCreateIndex($statement);
                return;

            case Statement::KIND_INSERT:
                yield from $this->convertInsert($statement);
                return;

            case Statement::KIND_DROP_TABLE:
                yield from $this->convertDropTable($statement);
                return;

            case Statement::KIND_COMMENT:
                // Comment-only input carries no meaning across dialects.
                return;

            case Statement::KIND_SET:
            case Statement::KIND_TRANSACTION:
                // Session setup is dialect-specific and rarely portable. The
                // target's own defaults are a better bet than a translation.
                $this->report->warn(
                    $statement->ordinal,
                    'Session/transaction statement dropped — the target connection manages its own session.',
                    Warning::LEVEL_SKIPPED,
                    $this->excerpt($statement->sql)
                );
                return;

            case Statement::KIND_MAINTENANCE:
                // LOCK TABLES / FLUSH / ANALYZE are MySQL server housekeeping.
                // They carry no schema or data, and mysqldump emits a LOCK and
                // an UNLOCK around every single table.
                $this->report->warn(
                    $statement->ordinal,
                    'Maintenance statement dropped — server housekeeping does not port across engines.',
                    Warning::LEVEL_SKIPPED,
                    $this->excerpt($statement->sql)
                );
                return;

            case Statement::KIND_DATABASE:
                // CREATE DATABASE / USE address a database, not a table. The
                // caller already chose the target database by picking the
                // connection, and passing these through would fail outright
                // (SQLite has no such statements at all).
                $this->report->warn(
                    $statement->ordinal,
                    'Database-level statement dropped — the target database is chosen by the connection.',
                    Warning::LEVEL_SKIPPED,
                    $this->excerpt($statement->sql)
                );
                return;

            default:
                $this->report->warn(
                    $statement->ordinal,
                    'Unsupported statement passed through unchanged.',
                    Warning::LEVEL_PASSTHROUGH,
                    $this->excerpt($statement->sql)
                );

                yield $this->terminate($statement->sql);
        }
    }

    /** @return \Generator<int,string> */
    private function convertCreateTable(Statement $statement): \Generator
    {
        $blueprint = $this->createTableParser->parse($statement);

        if ($blueprint === null) {
            yield $this->terminate($statement->sql);
            return;
        }

        $this->rememberSchema($blueprint);

        yield $this->grammar->compileCreate($blueprint);

        // Non-MySQL targets take their indexes as separate statements.
        yield from $this->grammar->compileIndexes($blueprint);
    }

    /** @return \Generator<int,string> */
    private function convertCreateIndex(Statement $statement): \Generator
    {
        $index = $this->indexParser->parse($statement);

        if ($index === null) {
            $this->report->warn(
                $statement->ordinal,
                'Unreadable CREATE INDEX passed through unchanged.',
                Warning::LEVEL_PASSTHROUGH,
                $this->excerpt($statement->sql)
            );

            yield $this->terminate($statement->sql);
            return;
        }

        if ($index['unique']) {
            // A unique index is a constraint; emitting it as a plain index
            // would silently drop the uniqueness guarantee.
            $this->report->warn(
                $statement->ordinal,
                "Unique index on [{$index['table']}] emitted as CREATE UNIQUE INDEX.",
                Warning::LEVEL_LOSSY
            );

            yield $this->uniqueIndexSql($index);
            return;
        }

        yield $this->grammar->compileCreateIndex($index['table'], [
            'columns' => $index['columns'],
            'name'    => $index['name'],
        ]);
    }

    /** @return \Generator<int,string> */
    private function convertDropTable(Statement $statement): \Generator
    {
        $sql = $statement->body();

        if (!preg_match('/^DROP\s+TABLE\s+(IF\s+EXISTS\s+)?/i', $sql, $m)) {
            yield $this->terminate($statement->sql);
            return;
        }

        $pos        = strlen($m[0]);
        $ifExists   = trim($m[1] ?? '') !== '';
        $tables     = [];

        // DROP TABLE a, b, c;
        foreach (SqlScanner::splitTopLevel(substr($sql, $pos)) as $entry) {
            $entryPos = 0;
            $table    = SqlScanner::readIdentifier($entry, $entryPos);

            if ($table !== null && $table !== '') {
                $tables[] = $table;
            }
        }

        if ($tables === []) {
            yield $this->terminate($statement->sql);
            return;
        }

        foreach ($tables as $table) {
            yield $ifExists
                ? $this->grammar->compileDropIfExists($table)
                : $this->grammar->compileDrop($table);
        }
    }

    /** @return \Generator<int,string> */
    private function convertInsert(Statement $statement): \Generator
    {
        $parsed = $this->insertParser->parse($statement);

        if ($parsed === null) {
            yield $this->terminate($statement->sql);
            return;
        }

        $table   = $parsed['table'];
        $columns = $parsed['columns'];
        $types   = $this->schema[$table] ?? [];

        $prefix = 'INSERT INTO ' . $this->wrapTable($table);

        if ($columns !== []) {
            $prefix .= ' (' . implode(', ', array_map([$this, 'wrapColumn'], $columns)) . ')';
        }

        foreach ($this->insertParser->tuples($statement, $parsed['values']) as $tuple) {
            $values = [];

            foreach ($tuple as $index => $value) {
                // Without a column list the tuple is positional, so fall back
                // to the declaration order recorded from CREATE TABLE.
                $column = $columns[$index] ?? array_keys($types)[$index] ?? null;

                $values[] = $this->literals->translate(
                    $value,
                    $column === null ? null : ($types[$column] ?? null),
                    $statement->ordinal
                );
            }

            yield $prefix . ' VALUES (' . implode(', ', $values) . ');';
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Record column types so INSERT translation knows what a 0 means. */
    private function rememberSchema(Blueprint $blueprint): void
    {
        $types = [];

        foreach ($blueprint->getColumns() as $column) {
            $types[$column['name']] = $column['type'];
        }

        $this->schema[$blueprint->getTable()] = $types;
    }

    /**
     * CREATE UNIQUE INDEX in the target's identifier quoting.
     *
     * @param array{table:string,name:?string,columns:string[]} $index
     */
    private function uniqueIndexSql(array $index): string
    {
        $name = $index['name'] ?? 'uq_' . implode('_', $index['columns']);
        $cols = implode(', ', array_map([$this, 'wrapColumn'], $index['columns']));

        return 'CREATE UNIQUE INDEX ' . $this->wrapColumn($name)
            . ' ON ' . $this->wrapTable($index['table']) . " ({$cols});";
    }

    /**
     * Quote an identifier the way the target grammar does.
     *
     * The grammars keep wrapColumn()/wrapTable() protected, so a tiny bound
     * closure is used rather than duplicating the quoting rules here — a third
     * copy of them is exactly what this feature is trying to avoid.
     */
    private function wrapColumn(string $name): string
    {
        return $this->wrap('wrapColumn', $name);
    }

    private function wrapTable(string $name): string
    {
        return $this->wrap('wrapTable', $name);
    }

    private function wrap(string $method, string $name): string
    {
        static $wrappers = [];

        $key = $this->to . '::' . $method;

        $wrappers[$key] ??= \Closure::bind(
            fn(string $value): string => $this->{$method}($value),
            $this->grammar,
            $this->grammar
        );

        return ($wrappers[$key])($name);
    }

    /** Ensure a passed-through statement still ends with a delimiter. */
    private function terminate(string $sql): string
    {
        $sql = rtrim($sql);

        return str_ends_with($sql, ';') ? $sql : $sql . ';';
    }

    private function toggleForeignKeys(\PDO $pdo, bool $enabled): void
    {
        $sql = match ($this->to) {
            'mysql'  => 'SET FOREIGN_KEY_CHECKS = ' . ($enabled ? '1' : '0'),
            'pgsql'  => 'SET session_replication_role = ' . ($enabled ? 'DEFAULT' : 'replica'),
            'sqlite' => 'PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF'),
            default  => null,
        };

        if ($sql === null) {
            return;
        }

        try {
            $pdo->exec($sql);
        } catch (\PDOException) {
            // Not every deployment grants the privilege (PostgreSQL's
            // session_replication_role needs superuser). Ordering may then
            // matter, but that is better than refusing to run at all.
        }
    }

    /** Resolve a driver alias to its canonical name. */
    private function canonical(string $driver): string
    {
        try {
            return DriverFactory::make(['driver' => $driver])->getName();
        } catch (\Throwable $e) {
            throw new ConverterException("Unknown driver [{$driver}].", 0, $e);
        }
    }

    private function excerpt(string $sql): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        return strlen($flat) > 120 ? substr($flat, 0, 117) . '...' : $flat;
    }
}
