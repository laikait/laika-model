<?php

declare(strict_types=1);

namespace Laika\Model\Schema\Grammars;

use Laika\Model\Schema\Blueprint;

class MySqlGrammar extends Grammar
{
    protected function wrapColumn(string $col): string { return '`' . str_replace('`', '``', $col) . '`'; }
    protected function wrapTable(string $table): string { return '`' . str_replace('`', '``', $table) . '`'; }

    public function compileCreate(Blueprint $blueprint): string
    {
        $table   = $this->wrapTable($blueprint->getTable());
        $columns = array_map([$this, 'columnToSql'], $blueprint->getColumns());
        $lines   = array_merge($columns, $this->compileConstraints($blueprint));

        $sql = "CREATE TABLE {$table} (\n  " . implode(",\n  ", $lines) . "\n)";

        if ($engine = $blueprint->getOption('engine')) {
            $sql .= " ENGINE={$engine}";
        }
        if ($charset = $blueprint->getOption('charset')) {
            $sql .= " DEFAULT CHARSET={$charset}";
        }
        if ($collation = $blueprint->getOption('collation')) {
            $sql .= " COLLATE={$collation}";
        }
        if ($blueprint->getOption('ifNotExists')) {
            $sql = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $sql);
        }

        return $sql . ';';
    }

    public function compileAddColumns(Blueprint $blueprint): string
    {
        $table = $this->wrapTable($blueprint->getTable());
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
        return "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?";
    }

    public function compileColumnExists(): string
    {
        return "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?";
    }

    public function compileRenameTable(string $from, string $to): string
    {
        return "RENAME TABLE {$this->wrapTable($from)} TO {$this->wrapTable($to)};";
    }

    protected function typeId(array $col): string { return 'INT UNSIGNED'; }
    protected function typeBigId(array $col): string { return 'BIGINT UNSIGNED'; }
    protected function typeTinyInteger(array $col): string { return 'TINYINT'; }
    protected function typeJson(array $col): string { return 'JSON'; }
    protected function columnComment(string $comment): string { return "COMMENT '" . addslashes($comment) . "'"; }
    protected function typeEnum(array $col): string
    {
        $values = implode(', ', array_map(
            fn($v) => "'" . addslashes($v) . "'",
            $col['values'] ?? []
        ));
        return "ENUM({$values})";
    }

    protected function typeSet(array $col): string
    {
        $values = implode(', ', array_map(
            fn($v) => "'" . addslashes($v) . "'",
            $col['values'] ?? []
        ));
        return "SET({$values})";
    }
}
