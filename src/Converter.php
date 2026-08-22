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

use Laika\Model\Converter\BlueprintBuilder;
use Laika\Model\Converter\LiteralTranslator;
use Laika\Model\Converter\Parsers\CreateTableParser;
use Laika\Model\Converter\Parsers\AlterTableParser;
use Laika\Model\Converter\Parsers\CopyParser;
use Laika\Model\Converter\Parsers\IndexParser;
use Laika\Model\Converter\Parsers\InsertParser;
use Laika\Model\Converter\Report;
use Laika\Model\Converter\SqlScanner;
use Laika\Model\Converter\Statement;
use Laika\Model\Converter\StatementReader;
use Laika\Model\Converter\TypeLexicon;
use Laika\Model\Converter\Warning;
use Laika\Model\Drivers\DriverFactory;
use Laika\Model\Exceptions\ConverterException;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Grammars\FirebirdGrammar;
use Laika\Model\Schema\Grammars\Grammar;
use Laika\Model\Schema\Grammars\MySqlGrammar;
use Laika\Model\Schema\Grammars\OracleGrammar;
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
 *   // Or copy a whole live database into another, with no dump file to manage:
 *   Converter::between('legacy_mysql', 'new_pgsql')->migrate();
 *
 *   print_r($converter->report()->warnings());
 */
final class Converter
{
    /** @var array<string,class-string<Grammar>> Canonical driver => grammar. */
    private const GRAMMARS = [
        'mysql'     => MySqlGrammar::class,
        'mariadb'   => MySqlGrammar::class,
        'pgsql'     => PgSqlGrammar::class,
        'postgres'  => PgSqlGrammar::class,
        'sqlite'    => SqliteGrammar::class,
        'sqlite3'   => SqliteGrammar::class,
        'sqlsrv'    => SqlSrvGrammar::class,
        'oci'       => OracleGrammar::class,
        'oracle'    => OracleGrammar::class,
        'firebird'  => FirebirdGrammar::class,
        'ibase'     => FirebirdGrammar::class,
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
    private AlterTableParser $alterTableParser;
    private CopyParser $copyParser;

    /**
     * Canonical types of the columns of every table seen so far, keyed by
     * table then column. INSERT literal translation needs it: whether `0`
     * becomes FALSE depends on the column being a boolean.
     *
     * @var array<string,array<string,string>>
     */
    private array $schema = [];

    /**
     * Full column definitions of every table seen so far, keyed by table then
     * column. $schema keeps only canonical type names, which is all the INSERT
     * translator needs; rebuilding a column as AUTO_INCREMENT needs the whole
     * definition, so it is kept separately rather than widening $schema and
     * disturbing that path.
     *
     * @var array<string,array<string,array<string,mixed>>>
     */
    private array $columns = [];

    /**
     * Statements held back until the end of the stream.
     *
     * @var string[]
     */
    private array $deferred = [];

    /**
     * The COPY block currently being read, from its header.
     *
     * @var ?array{table:string,columns:string[],text:bool}
     */
    private ?array $copyTarget = null;

    /**
     * Connection names remembered by between(), so migrate() can be called
     * with no arguments. reset() deliberately leaves these alone — they
     * describe the instance, not the conversion in progress.
     */
    private ?string $fromConnection = null;
    private ?string $toConnection   = null;

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

    /**
     * Build a converter for two registered connections.
     *
     * Both dialects are read from the connections themselves, so they can never
     * disagree with the databases actually involved — the failure mode of
     * hand-writing `new Converter('mysql', 'pgsql')` and then pointing it at a
     * SQLite connection.
     *
     * @param string $fromConnection Source connection name.
     * @param string $toConnection   Target connection name.
     * @throws ConverterException When either connection is unregistered.
     */
    public static function between(string $fromConnection, string $toConnection, bool $strict = false): self
    {
        $converter = new self(Connection::driver($fromConnection), Connection::driver($toConnection), $strict);

        $converter->fromConnection = $fromConnection;
        $converter->toConnection   = $toConnection;

        return $converter;
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Copy a whole database from one live connection to another.
     *
     * Dumps the source as plain SQL via Backup::dump(), converts it and executes
     * it against the target — the two halves that already existed, joined up.
     *
     * The source database is only read from. The target is overwritten: the
     * generated SQL drops each table before recreating it, so an existing table
     * of the same name is replaced rather than merged into.
     *
     * @param ?string $fromConnection Source connection (default: the one given to between()).
     * @param ?string $toConnection   Target connection (default: the one given to between()).
     * @param ?string $keep Write the intermediate SQL here and leave it in place.
     *                      Useful for auditing a migration. When null a temp file
     *                      is used and removed afterwards, even on failure.
     * @throws ConverterException When a connection's driver disagrees with this
     *                            converter's dialects, or nothing was executed.
     * @return int Number of statements executed against the target.
     */
    public function migrate(?string $fromConnection = null, ?string $toConnection = null, ?string $keep = null): int
    {
        $source = $fromConnection ?? $this->fromConnection;
        $target = $toConnection   ?? $this->toConnection;

        if ($source === null || $target === null) {
            throw new ConverterException(
                'migrate() needs both connection names. Pass them here, or build the '
                . 'converter with Converter::between($from, $to).'
            );
        }

        // apply() guards the target; the source needs the same check, or a dump
        // would be parsed as the wrong dialect and fail deep inside the parser.
        if (Connection::driver($source) !== $this->from) {
            throw new ConverterException(
                "Connection [{$source}] is a [" . Connection::driver($source) . '] connection, '
                . "but this converter reads [{$this->from}]."
            );
        }

        $file = $keep ?? $this->temporaryFile();

        try {
            (new Backup($source))->dump($file);

            return $this->apply($file, $target);
        } finally {
            // A caller-supplied path is theirs to keep; ours is not.
            if ($keep === null && is_file($file)) {
                @unlink($file);
            }
        }
    }

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

        // A few statements cannot run where the source put them. MySQL refuses
        // AUTO_INCREMENT on a column that is not yet a key, and pg_dump sets the
        // sequence default before it adds the primary key — so those are held
        // back to here. One entry per table, so this stays a stream.
        foreach ($this->deferred as $sql) {
            yield $sql;
        }

        $this->deferred = [];
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
        $this->columns    = [];
        $this->deferred   = [];
        $this->copyTarget = null;

        $this->createTableParser = new CreateTableParser(
            $this->lexicon,
            new BlueprintBuilder($this->lexicon, $this->report, $this->to),
            $this->report
        );
        $this->insertParser     = new InsertParser($this->report);
        $this->indexParser      = new IndexParser();
        $this->alterTableParser = new AlterTableParser();
        $this->copyParser       = new CopyParser();
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
        // `sqlite3 .dump` emits DELETE FROM sqlite_sequence and an INSERT per
        // AUTOINCREMENT table. That table is a SQLite internal that no other
        // engine has, and SQLite recreates it on its own — so it is bookkeeping
        // rather than data. SQLite reserves the sqlite_ prefix, so this can
        // never collide with a user table.
        if (preg_match('/\b(?:INTO|FROM|UPDATE)\s+["`\[]?sqlite_sequence\b/i', $statement->sql)) {
            $this->report->warn(
                $statement->ordinal,
                'SQLite internal sqlite_sequence statement dropped — the target engine keeps its own sequence state.',
                Warning::LEVEL_SKIPPED,
                $this->excerpt($statement->sql)
            );
            return;
        }

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

            case Statement::KIND_COPY:
                yield from $this->beginCopy($statement);
                return;

            case Statement::KIND_COPY_DATA:
                yield from $this->convertCopyRow($statement);
                return;

            case Statement::KIND_DROP_TABLE:
                yield from $this->convertDropTable($statement);
                return;

            case Statement::KIND_ALTER_TABLE:
                yield from $this->convertAlterTable($statement);
                return;

            case Statement::KIND_SEQUENCE:
                // The sequence object has no equivalent on MySQL or SQLite.
                // What it means is recovered from the table's own
                // "SET DEFAULT nextval(...)", which becomes auto-increment.
                $this->report->warn(
                    $statement->ordinal,
                    'Sequence statement dropped — the identity it carries is applied to the column instead.',
                    Warning::LEVEL_SKIPPED,
                    $this->excerpt($statement->sql)
                );
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

    /**
     * Open a COPY data block.
     *
     * The header itself emits nothing — it only says where the rows that follow
     * belong. The rows arrive as separate statements and are turned into
     * INSERTs one at a time.
     *
     * @return \Generator<int,string>
     */
    private function beginCopy(Statement $statement): \Generator
    {
        // The lexer only switches into copy mode for PostgreSQL, so a COPY
        // header from anywhere else has no data block behind it. Swallowing it
        // would drop a statement silently.
        $header = $this->from === 'pgsql' ? $this->copyParser->header($statement) : null;

        if ($header === null || !$header['text']) {
            // A CSV or binary block is a different encoding entirely. Decoding
            // it with the text-format rules would corrupt every row, so say so
            // rather than guess.
            $this->copyTarget = null;

            $this->report->warn(
                $statement->ordinal,
                $header === null
                    ? 'COPY header could not be parsed; its data block is passed through unchanged.'
                    : 'COPY block is not in text format — only the default text encoding is decoded.',
                Warning::LEVEL_PASSTHROUGH,
                $this->excerpt($statement->sql)
            );

            yield $this->terminate($statement->sql);
            return;
        }

        $this->copyTarget = $header;

        yield from [];
    }

    /**
     * Turn one COPY data row into an INSERT.
     *
     * @return \Generator<int,string>
     */
    private function convertCopyRow(Statement $statement): \Generator
    {
        if ($this->copyTarget === null) {
            // The header was unparseable, so the rows have nowhere to go. They
            // were already reported when the block opened; passing the raw row
            // through keeps the data visible rather than dropping it silently.
            yield $this->terminate($statement->sql);
            return;
        }

        $table   = $this->copyTarget['table'];
        $columns = $this->copyTarget['columns'];
        $types   = $this->schema[$table] ?? [];

        $prefix = 'INSERT INTO ' . $this->wrapTable($table);

        if ($columns !== []) {
            $prefix .= ' (' . implode(', ', array_map([$this, 'wrapColumn'], $columns)) . ')';
        }

        $values = [];

        foreach ($this->copyParser->fields($statement->sql) as $index => $value) {
            // Without a column list the row is positional, exactly as an
            // INSERT without one is.
            $column = $columns[$index] ?? array_keys($types)[$index] ?? null;
            $type   = $column === null ? null : ($types[$column] ?? null);

            $values[] = $this->literals->translate(
                $this->copyLiteral($value, $type),
                $type,
                $statement->ordinal
            );
        }

        yield $prefix . ' VALUES (' . implode(', ', $values) . ');';
    }

    /**
     * Render a decoded COPY field as a literal in the *source* dialect.
     *
     * Going back through LiteralTranslator rather than quoting for the target
     * directly is deliberate: it is the one place that knows about zero dates,
     * blobs and boolean spellings, and duplicating any of that here would mean
     * two versions to keep in step.
     */
    private function copyLiteral(?string $value, ?string $type): string
    {
        if ($value === null) {
            return 'NULL';
        }

        // A numeric column takes the value bare, so the output reads as numbers
        // rather than quoted digits. Anything unrecognised is quoted, which
        // every target accepts.
        if ($this->isNumericType($type) && preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', $value)) {
            return $value;
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }

    private function isNumericType(?string $type): bool
    {
        return in_array($type, [
            'id', 'bigId', 'integer', 'bigInteger', 'mediumInteger',
            'smallInteger', 'tinyInteger', 'decimal', 'float', 'double',
        ], true);
    }

    /**
     * Translate the ALTER TABLE forms a dump actually contains.
     *
     * pg_dump reattaches the primary key, the unique constraints and the
     * identity here rather than inside CREATE TABLE, so these carry real schema
     * meaning — dropping them would hand back a keyless table.
     *
     * @return \Generator<int,string>
     */
    private function convertAlterTable(Statement $statement): \Generator
    {
        $parsed = $this->alterTableParser->parse($statement);

        if ($parsed === null) {
            // Not a form we understand. Passing it through unchanged is wrong
            // more often than not, but it is honest and it is reported.
            $this->report->warn(
                $statement->ordinal,
                'Unsupported ALTER TABLE passed through unchanged.',
                Warning::LEVEL_PASSTHROUGH,
                $this->excerpt($statement->sql)
            );

            yield $this->terminate($statement->sql);
            return;
        }

        $table = $this->wrapTable($parsed['table']);

        switch ($parsed['form']) {
            case AlterTableParser::DROPPABLE:
                $this->report->warn(
                    $statement->ordinal,
                    'Ownership statement dropped — the target manages its own roles.',
                    Warning::LEVEL_SKIPPED,
                    $this->excerpt($statement->sql)
                );
                return;

            case AlterTableParser::ADD_UNIQUE:
                // A unique index rather than a table constraint: it says the
                // same thing and is the one spelling every target accepts,
                // including SQLite, which cannot ALTER in a constraint at all.
                yield $this->uniqueIndexSql([
                    'table'   => $parsed['table'],
                    'name'    => $parsed['name'],
                    'columns' => $parsed['columns'],
                ]);
                return;

            case AlterTableParser::ADD_PRIMARY_KEY:
                if ($this->to === 'sqlite') {
                    $this->report->warn(
                        $statement->ordinal,
                        'PRIMARY KEY dropped — SQLite cannot add a constraint after the table exists. '
                        . 'Declare it in the CREATE TABLE instead.',
                        Warning::LEVEL_SKIPPED,
                        $this->excerpt($statement->sql)
                    );
                    return;
                }

                $columns = implode(', ', array_map([$this, 'wrapColumn'], $parsed['columns']));

                // MySQL has no per-constraint naming for a primary key; there
                // is only ever one, and it is always called PRIMARY.
                yield $this->to === 'mysql'
                    ? "ALTER TABLE {$table} ADD PRIMARY KEY ({$columns});"
                    : "ALTER TABLE {$table} ADD CONSTRAINT "
                        . $this->wrapColumn($parsed['name'] ?? $parsed['table'] . '_pkey')
                        . " PRIMARY KEY ({$columns});";
                return;

            case AlterTableParser::ADD_FOREIGN_KEY:
                if ($this->to === 'sqlite') {
                    $this->report->warn(
                        $statement->ordinal,
                        'FOREIGN KEY dropped — SQLite cannot add a constraint after the table exists.',
                        Warning::LEVEL_SKIPPED,
                        $this->excerpt($statement->sql)
                    );
                    return;
                }

                yield $this->foreignKeySql($parsed);
                return;

            case AlterTableParser::AUTO_INCREMENT:
                yield from $this->applyAutoIncrement($statement, $parsed);
                return;
        }
    }

    /** @param array<string,mixed> $parsed A parsed ADD_FOREIGN_KEY. */
    private function foreignKeySql(array $parsed): string
    {
        $columns   = implode(', ', array_map([$this, 'wrapColumn'], $parsed['columns']));
        $reference = $parsed['references'];
        $foreign   = implode(', ', array_map([$this, 'wrapColumn'], $reference['columns']));

        $sql = "ALTER TABLE {$this->wrapTable($parsed['table'])} ADD CONSTRAINT "
            . $this->wrapColumn($parsed['name'] ?? "fk_{$parsed['table']}_" . implode('_', $parsed['columns']))
            . " FOREIGN KEY ({$columns})"
            . " REFERENCES {$this->wrapTable($reference['table'])} ({$foreign})";

        // ON DELETE / ON UPDATE read the same on every target.
        if ($parsed['actions'] !== '') {
            $sql .= ' ' . $parsed['actions'];
        }

        return $sql . ';';
    }

    /**
     * Turn PostgreSQL's "SET DEFAULT nextval(...)" back into the target's own
     * auto-increment.
     *
     * Only MySQL can gain one after the fact. PostgreSQL targets already get a
     * SERIAL out of the CREATE TABLE conversion, while SQL Server IDENTITY and
     * SQLite AUTOINCREMENT can only be declared at creation time.
     *
     * @param array<string,mixed> $parsed
     * @return \Generator<int,string>
     */
    private function applyAutoIncrement(Statement $statement, array $parsed): \Generator
    {
        if ($this->to !== 'mysql') {
            $this->report->warn(
                $statement->ordinal,
                "Auto-increment default dropped — [{$this->to}] can only declare one when the table is created.",
                Warning::LEVEL_SKIPPED,
                $this->excerpt($statement->sql)
            );
            return;
        }

        $column = $this->columns[$parsed['table']][$parsed['column']] ?? null;

        if ($column === null) {
            $this->report->warn(
                $statement->ordinal,
                "Auto-increment default dropped — column [{$parsed['column']}] was not seen in a CREATE TABLE.",
                Warning::LEVEL_SKIPPED,
                $this->excerpt($statement->sql)
            );
            return;
        }

        $column['auto_increment'] = true;
        $column['nullable']       = false;

        // MySQL rejects a DEFAULT on an AUTO_INCREMENT column, and the default
        // being replaced here is exactly the nextval() this came from.
        unset($column['default']);

        // Render through the grammar's own columnToSql() so the type mapping
        // and the AUTO_INCREMENT keyword come from the one place that defines
        // them, rather than being spelled out a second time here.
        $definition = $this->columnSql($column);

        // Deferred: MySQL rejects AUTO_INCREMENT on a column that is not yet a
        // key, and the primary key arrives later in the dump.
        $this->deferred[] = "ALTER TABLE {$this->wrapTable($parsed['table'])} MODIFY COLUMN {$definition};";

        yield from [];
    }

    /** @param array<string,mixed> $column */
    private function columnSql(array $column): string
    {
        $render = \Closure::bind(
            fn (Grammar $grammar): string => $grammar->columnToSql($column),
            null,
            Grammar::class
        );

        return $render($this->grammar);
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
        $types       = [];
        $definitions = [];

        foreach ($blueprint->getColumns() as $column) {
            $types[$column['name']]       = $column['type'];
            $definitions[$column['name']] = $column;
        }

        $this->schema[$blueprint->getTable()]  = $types;
        $this->columns[$blueprint->getTable()] = $definitions;
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

    /**
     * A temp file for the intermediate dump.
     *
     * tempnam() creates the file immediately, which is what we want: the dump
     * tools write into an existing path, and the reservation avoids a race with
     * a concurrent migration picking the same name.
     */
    private function temporaryFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'laika-migrate-');

        if ($file === false) {
            throw new ConverterException('Cannot create a temporary file for the migration dump.');
        }

        return $file;
    }

    private function excerpt(string $sql): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        return strlen($flat) > 120 ? substr($flat, 0, 117) . '...' : $flat;
    }
}
