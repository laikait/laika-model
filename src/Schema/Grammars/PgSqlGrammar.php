<?php

declare(strict_types=1);

namespace Laika\Model\Schema\Grammars;

use Laika\Model\Schema\Blueprint;

class PgSqlGrammar extends Grammar
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
        $table  = $this->wrapTable($blueprint->getTable());
        $alters = array_map(
            fn($col) => 'ADD COLUMN ' . $this->columnToSql($col),
            $blueprint->getColumns()
        );
        return "ALTER TABLE {$table}\n  " . implode(",\n  ", $alters) . ';';
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
        return "SELECT COUNT(*) FROM information_schema.tables WHERE table_catalog = ? AND table_name = ?";
    }

    public function compileColumnExists(): string
    {
        return "SELECT COUNT(*) FROM information_schema.columns WHERE table_catalog = ? AND table_name = ? AND column_name = ?";
    }

    public function compileRenameTable(string $from, string $to): string
    {
        return "ALTER TABLE {$this->wrapTable($from)} RENAME TO {$this->wrapTable($to)};";
    }

    // PG uses SERIAL / BIGSERIAL for auto-increment
    protected function typeId(array $col): string      { return 'SERIAL'; }
    protected function typeBigId(array $col): string   { return 'BIGSERIAL'; }
    protected function typeBoolean(array $col): string { return 'BOOLEAN'; }
    protected function typeJson(array $col): string    { return 'JSONB'; }
    protected function typeDateTime(array $col): string { return 'TIMESTAMP'; }
    protected function typeLongText(array $col): string { return 'TEXT'; }
    protected function typeMediumText(array $col): string { return 'TEXT'; }
    protected function typeBinary(array $col): string  { return 'BYTEA'; }
    protected function typeUuid(array $col): string    { return 'UUID'; }
    protected function autoIncrementKeyword(): string  { return ''; } // handled by SERIAL
    protected function typeEnum(array $col): string
    {
        $quoted = implode(', ', array_map(
            fn($v) => "'" . addslashes($v) . "'",
            $col['values'] ?? []
        ));
        $colName = $col['name'];
        return "VARCHAR(255) CHECK ({$colName} IN ({$quoted}))";
    }

    protected function typeSet(array $col): string
    {
        return 'TEXT';
    }
}
