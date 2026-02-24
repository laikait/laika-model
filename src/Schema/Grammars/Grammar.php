<?php

declare(strict_types=1);

namespace Laika\Model\Schema\Grammars;

use Laika\Model\Schema\Blueprint;

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

    /** Convert a Column Definition Array to SQL Fragment */
    protected function columnToSql(array $col): string
    {
        $sql = $this->wrapColumn($col['name']) . ' ' . $this->resolveType($col);

        if (!empty($col['unsigned']) && !str_contains($sql, 'UNSIGNED')) {
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

        if (!empty($col['auto_increment'])) {
            $sql .= ' ' . $this->autoIncrementKeyword();
        }

        if (!empty($col['comment'])) {
            $sql .= ' ' . $this->columnComment($col['comment']);
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
            'smallInteger'   => $this->typeSmallInteger($col),
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
    protected function typeBoolean(array $col): string    { return 'TINYINT(1)'; }
    protected function typeString(array $col): string     { return 'VARCHAR(' . ($col['length'] ?? 255) . ')'; }
    protected function typeChar(array $col): string       { return 'CHAR(' . ($col['length'] ?? 36) . ')'; }
    protected function typeText(array $col): string       { return 'TEXT'; }
    protected function typeMediumText(array $col): string { return 'MEDIUMTEXT'; }
    protected function typeLongText(array $col): string   { return 'LONGTEXT'; }
    protected function typeDate(array $col): string       { return 'DATE'; }
    protected function typeTime(array $col): string       { return 'TIME'; }
    protected function typeDateTime(array $col): string   { return 'DATETIME'; }
    protected function typeTimestamp(array $col): string  { return 'TIMESTAMP'; }
    protected function typeJson(array $col): string       { return 'JSON'; }
    protected function typeBinary(array $col): string     { return 'BLOB'; }
    protected function typeUid(array $col): string       { return 'CHAR(38)'; }
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

    protected function autoIncrementKeyword(): string     { return 'AUTO_INCREMENT'; }

    protected function columnComment(string $comment): string { return ''; }

    protected function formatDefault(mixed $value): string
    {
        if (is_null($value))   return 'NULL';
        if (is_bool($value))   return $value ? '1' : '0';
        if (is_numeric($value)) return (string) $value;
        return "'" . addslashes((string)$value) . "'";
    }

    /** Build PRIMARY KEY + INDEX + UNIQUE + FOREIGN constraint SQL fragments */
    protected function compileConstraints(Blueprint $blueprint): array
    {
        $lines = [];

        // Primary keys
        if ($pk = $blueprint->getPrimaryKey()) {
            $cols = implode(', ', array_map([$this, 'wrapColumn'], $pk));
            $lines[] = "PRIMARY KEY ({$cols})";
        }

        // Unique
        foreach ($blueprint->getUniques() as $unique) {
            $name = $unique['name'] ?? 'uq_' . implode('_', $unique['columns']);
            $cols = implode(', ', array_map([$this, 'wrapColumn'], $unique['columns']));
            $lines[] = "CONSTRAINT {$this->wrapColumn($name)} UNIQUE ({$cols})";
        }

        // Indexes (added separately via ALTER in some dbs; here inline for simplicity)
        foreach ($blueprint->getIndexes() as $index) {
            $name = $index['name'] ?? 'idx_' . implode('_', $index['columns']);
            $cols = implode(', ', array_map([$this, 'wrapColumn'], $index['columns']));
            $lines[] = "INDEX {$this->wrapColumn($name)} ({$cols})";
        }

        // Foreign keys
        foreach ($blueprint->getForeignKeys() as $fk) {
            $col  = $this->wrapColumn($fk['column']);
            $ref  = $this->wrapTable($fk['referenceTable']) . '(' . $this->wrapColumn($fk['referenceColumn']) . ')';
            $name = $fk['name'] ?? 'fk_' . $fk['column'];
            $line = "CONSTRAINT {$this->wrapColumn($name)} FOREIGN KEY ({$col}) REFERENCES {$ref}";
            if (!empty($fk['onDelete'])) $line .= " ON DELETE {$fk['onDelete']}";
            if (!empty($fk['onUpdate'])) $line .= " ON UPDATE {$fk['onUpdate']}";
            $lines[] = $line;
        }

        return $lines;
    }
}
