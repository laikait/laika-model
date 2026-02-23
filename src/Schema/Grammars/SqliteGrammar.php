<?php

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
    protected function typeId(array $col): string      { return 'INTEGER'; }
    protected function typeBigId(array $col): string   { return 'INTEGER'; }
    protected function typeBoolean(array $col): string { return 'INTEGER'; }   // 0/1
    protected function typeJson(array $col): string    { return 'TEXT'; }      // stored as JSON string
    protected function typeLongText(array $col): string  { return 'TEXT'; }
    protected function typeMediumText(array $col): string { return 'TEXT'; }
    protected function typeDateTime(array $col): string  { return 'TEXT'; }    // ISO 8601 text
    protected function typeTimestamp(array $col): string { return 'TEXT'; }
    protected function typeDate(array $col): string      { return 'TEXT'; }
    protected function typeTime(array $col): string      { return 'TEXT'; }
    protected function typeBinary(array $col): string    { return 'BLOB'; }
    protected function typeUuid(array $col): string      { return 'TEXT'; }
    protected function typeFloat(array $col): string     { return 'REAL'; }
    protected function typeDouble(array $col): string    { return 'REAL'; }

    /** SQLite has no AUTO_INCREMENT keyword — INTEGER PRIMARY KEY handles it. */
    protected function autoIncrementKeyword(): string { return ''; }

    /**
     * SQLite does not support UNSIGNED — silently strip it.
     */
    protected function columnToSql(array $col): string
    {
        // For id/bigId columns: INTEGER PRIMARY KEY is special in SQLite
        // It must NOT have NOT NULL or other constraints before PRIMARY KEY
        if (in_array($col['type'], ['id', 'bigId'])) {
            $name = $this->wrapColumn($col['name']);
            $sql  = "{$name} INTEGER PRIMARY KEY AUTOINCREMENT";
            if (!empty($col['comment'])) {
                // SQLite doesn't support inline comments, skip silently
            }
            return $sql;
        }

        // Strip unsigned for all other columns (SQLite ignores it but let's be clean)
        $col['unsigned'] = false;

        return parent::columnToSql($col);
    }

    /**
     * SQLite does not support inline INDEX in CREATE TABLE.
     * Return only PRIMARY KEY, UNIQUE, and FOREIGN KEY constraints.
     * Indexes should be created separately via CREATE INDEX.
     */
    protected function compileConstraints(Blueprint $blueprint): array
    {
        $lines = [];

        // UNIQUE constraints (inline is fine)
        foreach ($blueprint->getUniques() as $unique) {
            $name = $unique['name'] ?? 'uq_' . implode('_', $unique['columns']);
            $cols = implode(', ', array_map([$this, 'wrapColumn'], $unique['columns']));
            $lines[] = "CONSTRAINT {$this->wrapColumn($name)} UNIQUE ({$cols})";
        }

        // Foreign keys (SQLite supports them with PRAGMA foreign_keys = ON)
        foreach ($blueprint->getForeignKeys() as $fk) {
            $col  = $this->wrapColumn($fk['column']);
            $ref  = $this->wrapTable($fk['referenceTable']) . '(' . $this->wrapColumn($fk['referenceColumn']) . ')';
            $name = $fk['name'] ?? 'fk_' . $fk['column'];
            $line = "CONSTRAINT {$this->wrapColumn($name)} FOREIGN KEY ({$col}) REFERENCES {$ref}";
            if (!empty($fk['onDelete'])) $line .= " ON DELETE {$fk['onDelete']}";
            if (!empty($fk['onUpdate'])) $line .= " ON UPDATE {$fk['onUpdate']}";
            $lines[] = $line;
        }

        // NOTE: Indexes are intentionally omitted here.
        // SQLite does not support inline INDEX in CREATE TABLE.
        // Use Schema::statement("CREATE INDEX ...") or Schema::createIndexes() separately.

        return $lines;
    }
}
