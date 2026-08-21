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
use Laika\Model\Schema\Expression;

abstract class Grammar
{
    abstract public function compileCreate(Blueprint $blueprint): string;
    abstract public function compileAddColumns(Blueprint $blueprint): string;
    abstract public function compileDrop(string $table): string;
    abstract public function compileDropIfExists(string $table): string;
    abstract public function compileTableExists(): string;
    abstract public function compileColumnExists(): string;
    abstract public function compileRenameTable(string $from, string $to): string;

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    protected function wrapColumn(string $col): string
    {
        return '"' . str_replace('"', '""', $col) . '"';
    }

    protected function wrapTable(string $table): string
    {
        return '"' . str_replace('"', '""', $table) . '"';
    }

    /**
     * Whether the driver understands the UNSIGNED column modifier.
     * MySQL/MariaDB is the only one that does — everywhere else it is a syntax
     * error, so it must not be emitted.
     */
    protected function supportsUnsigned(): bool
    {
        return false;
    }

    /** Convert a Column Definition Array to SQL Fragment */
    protected function columnToSql(array $col): string
    {
        $sql = $this->wrapColumn($col['name']) . ' ' . $this->resolveType($col);

        if ($this->supportsUnsigned() && !empty($col['unsigned']) && !str_contains($sql, 'UNSIGNED')) {
            $sql .= ' UNSIGNED';
        }

        if (!empty($col['nullable'])) {
            $sql .= ' NULL';
        } else {
            $sql .= ' NOT NULL';
        }

        if (array_key_exists('default', $col)) {
            $sql .= ' DEFAULT ' . $this->formatDefault($col['default']);
        }

        // autoIncrementKeyword() and columnComment() return '' on drivers that
        // have no equivalent — appending unconditionally would leave a trailing
        // space on every such column.
        if (!empty($col['auto_increment']) && ($keyword = $this->autoIncrementKeyword()) !== '') {
            $sql .= ' ' . $keyword;
        }

        if (!empty($col['comment']) && ($comment = $this->columnComment($col['comment'])) !== '') {
            $sql .= ' ' . $comment;
        }

        return $sql;
    }

    protected function resolveType(array $col): string
    {
        return match ($col['type']) {
            'id'            => $this->typeId($col),
            'bigId'         => $this->typeBigId($col),
            'uid'           => $this->typeUid($col),
            'integer'       => $this->typeInteger($col),
            'bigInteger'    => $this->typeBigInteger($col),
            'smallInteger'  => $this->typeSmallInteger($col),
            'tinyInteger'   => $this->typeTinyInteger($col),
            'float'         => $this->typeFloat($col),
            'double'        => $this->typeDouble($col),
            'decimal'       => $this->typeDecimal($col),
            'boolean'       => $this->typeBoolean($col),
            'string'        => $this->typeString($col),
            'char'          => $this->typeChar($col),
            'text'          => $this->typeText($col),
            'mediumText'    => $this->typeMediumText($col),
            'longText'      => $this->typeLongText($col),
            'date'          => $this->typeDate($col),
            'time'          => $this->typeTime($col),
            'dateTime'      => $this->typeDateTime($col),
            'timestamp'     => $this->typeTimestamp($col),
            'json'          => $this->typeJson($col),
            'binary'        => $this->typeBinary($col),
            'tinyBlob'      => $this->typeTinyBlob($col),
            'blob'          => $this->typeBlob($col),
            'mediumBlob'    => $this->typeMediumBlob($col),
            'longBlob'      => $this->typeLongBlob($col),
            'enum'          => $this->typeEnum($col),
            'set'           => $this->typeSet($col),
            default         => strtoupper($col['type']),
        };
    }

    // --- Type methods (can be overridden per grammar) ---

    protected function typeId(array $col): string         { return 'INT'; }
    protected function typeBigId(array $col): string      { return 'BIGINT'; }
    protected function typeInteger(array $col): string    { return 'INT'; }
    protected function typeBigInteger(array $col): string { return 'BIGINT'; }
    protected function typeSmallInteger(array $col): string { return 'SMALLINT'; }
    protected function typeTinyInteger(array $col): string  { return 'TINYINT'; }
    protected function typeFloat(array $col): string      { return 'FLOAT'; }
    protected function typeDouble(array $col): string     { return 'DOUBLE'; }
    protected function typeDecimal(array $col): string
    {
        $p = $col['precision'] ?? 8;
        $s = $col['scale'] ?? 2;
        return "DECIMAL({$p},{$s})";
    }
    protected function typeBoolean(array $col): string      { return 'TINYINT(1)'; }
    protected function typeString(array $col): string       { return 'VARCHAR(' . ($col['length'] ?? 255) . ')'; }
    protected function typeChar(array $col): string         { return 'CHAR(' . ($col['length'] ?? 36) . ')'; }
    protected function typeText(array $col): string         { return 'TEXT'; }
    protected function typeMediumText(array $col): string   { return 'MEDIUMTEXT'; }
    protected function typeLongText(array $col): string     { return 'LONGTEXT'; }
    protected function typeDate(array $col): string         { return 'DATE'; }
    protected function typeTime(array $col): string         { return 'TIME'; }
    protected function typeDateTime(array $col): string     { return 'DATETIME'; }
    protected function typeTimestamp(array $col): string    { return 'TIMESTAMP'; }
    protected function typeJson(array $col): string         { return 'JSON'; }
    protected function typeTinyBlob(array $col): string     { return 'TINYBLOB'; }
    protected function typeBlob(array $col): string         { return 'BLOB'; }
    protected function typeMediumBlob(array $col): string   { return 'MEDIUMBLOB'; }
    protected function typeLongBlob(array $col): string     { return 'LONGBLOB'; }
    protected function typeBinary(array $col): string       { return 'BLOB'; }
    protected function typeUid(array $col): string          { return 'VARCHAR(38)'; }
    protected function typeEnum(array $col): string
    {
        // Default fallback for drivers without native ENUM
        // Use VARCHAR + CHECK constraint
        $quoted = implode(', ', array_map(
            fn($v) => "'" . addslashes($v) . "'",
            $col['values'] ?? []
        ));
        $colName = $col['name'];
        return "VARCHAR(255) CHECK ({$colName} IN ({$quoted}))";
    }

    protected function typeSet(array $col): string
    {
        // SET has no cross-driver equivalent — fall back to TEXT
        // MySQL overrides this with native SET
        return 'TEXT';
    }

    /** Dialect-neutral: grammars that need a keyword must opt in explicitly. */
    protected function autoIncrementKeyword(): string     { return ''; }

    protected function columnComment(string $comment): string { return ''; }

    protected function formatDefault(mixed $value): string
    {
        if (is_null($value))    return 'NULL';
        if (is_bool($value))    return $value ? '1' : '0';
        if (is_numeric($value)) return (string) $value;

        // Raw, unquoted SQL — e.g. CURRENT_TIMESTAMP. Expression is the explicit
        // form; a Closure is the legacy one used by Blueprint::timestamp().
        // Note this must NOT be is_callable(): that is true for ordinary strings
        // like 'time' or 'count', so a plain DEFAULT 'time' called time().
        if ($value instanceof Expression) return (string) $value;
        if ($value instanceof \Closure)   return (string) $value();

        return "'" . addslashes((string) $value) . "'";
    }

    /**
     * Prefix a constraint name, without double-prefixing.
     *
     * ltrim() would be wrong here — it strips a *character class*, so
     * ltrim('queue', 'uq_') is 'eue' and ltrim('did', 'idx_') is ''.
     */
    protected function prefixName(string $prefix, string $base): string
    {
        return str_starts_with($base, $prefix) ? $base : $prefix . $base;
    }

    /**
     * Compile a standalone CREATE INDEX statement.
     *
     * Inline `INDEX name (cols)` inside CREATE TABLE is MySQL-only syntax, so
     * every other driver emits its indexes as separate statements. Callers get
     * them from compileIndexes().
     *
     * @param array{columns: string[], name?: ?string} $index
     */
    public function compileCreateIndex(string $table, array $index): string
    {
        $base = $index['name'] ?? implode('_', $index['columns']);

        // PostgreSQL puts indexes in the schema namespace and SQLite puts them
        // in the database namespace, so a bare 'idx_userid' collides the moment
        // two tables index a column of the same name. Qualifying is the safe
        // default; MySQL and SQL Server scope index names to the table and
        // override qualifyIndexName() to keep the source's name.
        $name = $this->prefixName('idx_', $this->qualifyIndexName($table, $base));
        $cols = implode(', ', array_map([$this, 'wrapColumn'], $index['columns']));

        return "CREATE INDEX {$this->wrapColumn($name)} ON {$this->wrapTable($table)} ({$cols});";
    }

    /**
     * Qualify a bare index name with its table so it is unique schema-wide.
     *
     * Already-qualified names are left alone, so a source that names its index
     * after the table does not end up with the table twice.
     */
    protected function qualifyIndexName(string $table, string $base): string
    {
        return $this->qualifyWithTable($table, $base, 'idx_');
    }

    /**
     * Qualify a UNIQUE/FOREIGN KEY constraint name with its table.
     *
     * PostgreSQL backs a UNIQUE constraint with an index in the schema
     * namespace, and SQL Server scopes constraint names to the schema outright,
     * so `uq_asset_type` on two tables collides on both. MySQL scopes them to
     * the table and overrides this to keep the source's name.
     */
    protected function qualifyConstraintName(string $table, string $base, string $prefix): string
    {
        return $this->qualifyWithTable($table, $base, $prefix);
    }

    /**
     * Prepend the table name to $base unless it is already qualified.
     *
     * $prefix is stripped before the check and left for prefixName() to re-add,
     * so 'idx_userid' on 'tblemails' becomes 'tblemails_userid' rather than
     * 'tblemails_idx_userid'.
     */
    private function qualifyWithTable(string $table, string $base, string $prefix): string
    {
        $stem = str_starts_with($base, $prefix) ? substr($base, strlen($prefix)) : $base;

        if (str_starts_with($stem, $table . '_') || $stem === $table) {
            return $base;
        }

        return $table . '_' . $stem;
    }

    /**
     * Every CREATE INDEX statement a blueprint needs, in order.
     *
     * Empty on MySQL, where compileCreate() emits indexes inline instead.
     *
     * @return string[]
     */
    public function compileIndexes(Blueprint $blueprint): array
    {
        $table = $blueprint->getTable();

        return array_map(
            fn(array $index): string => $this->compileCreateIndex($table, $index),
            $blueprint->getIndexes()
        );
    }

    /** Build PRIMARY KEY + INDEX + UNIQUE + FOREIGN constraint SQL fragments */
    protected function compileConstraints(Blueprint $blueprint): array
    {
        $lines = [];
        $table = $blueprint->getTable();

        // Primary keys
        if ($pk = $blueprint->getPrimaryKey()) {
            $cols = implode(', ', array_map([$this, 'wrapColumn'], $pk));
            $lines[] = "PRIMARY KEY ({$cols})";
        }

        // Unique
        foreach ($blueprint->getUniques() as $unique) {
            $base = $unique['name'] ?? implode('_', $unique['columns']);
            $name = $this->prefixName('uq_', $this->qualifyConstraintName($table, $base, 'uq_'));
            $cols = implode(', ', array_map([$this, 'wrapColumn'], $unique['columns']));
            $lines[] = "CONSTRAINT {$this->wrapColumn($name)} UNIQUE ({$cols})";
        }

        // Indexes are NOT emitted here. Inline `INDEX name (cols)` is MySQL-only
        // syntax; MySqlGrammar overrides this method to add them. Every other
        // driver gets them from compileIndexes() as separate CREATE INDEX
        // statements.

        // Foreign keys
        foreach ($blueprint->getForeignKeys() as $fk) {
            $col  = $this->wrapColumn($fk['column']);
            $ref  = $this->wrapTable($fk['referenceTable']) . '(' . $this->wrapColumn($fk['referenceColumn']) . ')';
            $base = $fk['name'] ?? $fk['column'];
            $name = $this->prefixName('fk_', $this->qualifyConstraintName($table, $base, 'fk_'));
            $line = "CONSTRAINT {$this->wrapColumn($name)} FOREIGN KEY ({$col}) REFERENCES {$ref}";
            if (!empty($fk['onDelete'])) $line .= " ON DELETE {$fk['onDelete']}";
            if (!empty($fk['onUpdate'])) $line .= " ON UPDATE {$fk['onUpdate']}";
            $lines[] = $line;
        }

        return $lines;
    }
}
