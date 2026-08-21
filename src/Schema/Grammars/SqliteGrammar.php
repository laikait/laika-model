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

namespace Laika\Model\Schema\Grammars;

use Laika\Model\Schema\Blueprint;

/**
 * SQLite Grammar.
 *
 * Key SQLite quirks handled here:
 *  - No BIGINT AUTO_INCREMENT — uses INTEGER PRIMARY KEY (rowid alias)
 *  - No UNSIGNED keyword
 *  - No inline INDEX in CREATE TABLE — indexes must be CREATE INDEX statements
 *  - ALTER TABLE only supports ADD COLUMN (no DROP, RENAME COLUMN < 3.25)
 *  - BOOLEAN → INTEGER, JSON → TEXT, BLOB is fine
 *  - Foreign keys are OFF by default; enable with PRAGMA foreign_keys = ON
 *  - Quoting uses double-quotes (standard SQL)
 */
class SqliteGrammar extends Grammar
{
    public function compileCreate(Blueprint $blueprint): string
    {
        $table   = $this->wrapTable($blueprint->getTable());
        $columns = array_map([$this, 'columnToSql'], $blueprint->getColumns());
        $lines   = array_merge($columns, $this->compileConstraints($blueprint));
        $prefix  = $blueprint->getOption('ifNotExists') ? 'CREATE TABLE IF NOT EXISTS' : 'CREATE TABLE';

        return "{$prefix} {$table} (\n  " . implode(",\n  ", $lines) . "\n);";
    }

    public function compileAddColumns(Blueprint $blueprint): string
    {
        // SQLite only allows one ADD COLUMN per ALTER TABLE statement
        $table = $this->wrapTable($blueprint->getTable());
        $stmts = array_map(
            fn($col) => "ALTER TABLE {$table} ADD COLUMN " . $this->columnToSql($col) . ';',
            $blueprint->getColumns()
        );
        return implode("\n", $stmts);
    }

    public function compileDrop(string $table): string
    {
        return "DROP TABLE {$this->wrapTable($table)};";
    }

    public function compileDropIfExists(string $table): string
    {
        return "DROP TABLE IF EXISTS {$this->wrapTable($table)};";
    }

    public function compileTableExists(): string
    {
        // SQLite stores schema in sqlite_master
        return "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?";
    }

    public function compileColumnExists(): string
    {
        // pragma_table_info is available since SQLite 3.16
        return "SELECT COUNT(*) FROM pragma_table_info(?) WHERE name = ?";
    }

    public function compileRenameTable(string $from, string $to): string
    {
        return "ALTER TABLE {$this->wrapTable($from)} RENAME TO {$this->wrapTable($to)};";
    }

    // -----------------------------------------------------------------------
    // SQLite type overrides
    // -----------------------------------------------------------------------

    /**
     * INTEGER PRIMARY KEY is the rowid alias — enables true auto-increment.
     * Must NOT include UNSIGNED or AUTO_INCREMENT keywords.
     */
    protected function typeId(array $col): string           { return 'INTEGER'; }
    protected function typeBigId(array $col): string        { return 'INTEGER'; }
    protected function typeBoolean(array $col): string      { return 'INTEGER'; }   // 0/1
    protected function typeJson(array $col): string         { return 'TEXT'; }      // stored as JSON string
    protected function typeLongText(array $col): string     { return 'TEXT'; }
    protected function typeMediumText(array $col): string   { return 'TEXT'; }
    protected function typeDateTime(array $col): string     { return 'TEXT'; }    // ISO 8601 text
    protected function typeTimestamp(array $col): string    { return 'TEXT'; }
    protected function typeDate(array $col): string         { return 'TEXT'; }
    protected function typeTime(array $col): string         { return 'TEXT'; }
    protected function typeBinary(array $col): string       { return 'BLOB'; }
    protected function typeUid(array $col): string          { return 'TEXT'; }
    protected function typeFloat(array $col): string        { return 'REAL'; }
    protected function typeDouble(array $col): string       { return 'REAL'; }
    protected function typeTinyBlob(array $col): string     { return 'BLOB'; }
    protected function typeBlob(array $col): string         { return 'BLOB'; }
    protected function typeMediumBlob(array $col): string   { return 'BLOB'; }
    protected function typeLongBlob(array $col): string     { return 'BLOB'; }

    /** SQLite has no AUTO_INCREMENT keyword — INTEGER PRIMARY KEY handles it. */
    protected function autoIncrementKeyword(): string { return ''; }

    /**
     * id/bigId become INTEGER PRIMARY KEY AUTOINCREMENT — the rowid alias.
     *
     * SQLite requires that exact spelling and rejects NOT NULL / DEFAULT
     * appearing before PRIMARY KEY, so these columns bypass the shared emitter.
     * UNSIGNED is handled by supportsUnsigned() returning false in the base.
     */
    protected function columnToSql(array $col): string
    {
        if (in_array($col['type'], ['id', 'bigId'], true)) {
            // Comments have no inline form in SQLite and are dropped here.
            return $this->wrapColumn($col['name']) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        return parent::columnToSql($col);
    }

    /**
     * Same as the base, minus a PRIMARY KEY line when an id/bigId column has
     * already declared it inline — SQLite rejects two primary keys on a table.
     *
     * Indexes are not emitted inline (SQLite has no inline INDEX); the base
     * compileIndexes() produces separate CREATE INDEX statements instead.
     */
    protected function compileConstraints(Blueprint $blueprint): array
    {
        $lines = parent::compileConstraints($blueprint);

        if (!$this->hasInlinePrimaryKey($blueprint)) {
            return $lines;
        }

        // Drop the base's "PRIMARY KEY (...)" line; the id column carries it.
        return array_values(array_filter(
            $lines,
            fn(string $line): bool => !str_starts_with($line, 'PRIMARY KEY (')
        ));
    }

    /** True when a column already emits INTEGER PRIMARY KEY inline. */
    private function hasInlinePrimaryKey(Blueprint $blueprint): bool
    {
        foreach ($blueprint->getColumns() as $col) {
            if (in_array($col['type'], ['id', 'bigId'], true)) {
                return true;
            }
        }

        return false;
    }
}
