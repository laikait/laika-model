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

    /** MySQL/MariaDB is the only supported driver with an UNSIGNED modifier. */
    protected function supportsUnsigned(): bool { return true; }

    /**
     * MySQL accepts inline `INDEX name (cols)` inside CREATE TABLE, so keep
     * emitting indexes there rather than as separate statements.
     */
    protected function compileConstraints(Blueprint $blueprint): array
    {
        $lines = parent::compileConstraints($blueprint);

        foreach ($blueprint->getIndexes() as $index) {
            $base = $index['name'] ?? implode('_', $index['columns']);
            $name = $this->prefixName('idx_', $base);
            $cols = implode(', ', array_map([$this, 'wrapColumn'], $index['columns']));
            $lines[] = "INDEX {$this->wrapColumn($name)} ({$cols})";
        }

        return $lines;
    }

    /** Indexes are already inline in compileCreate(). */
    public function compileIndexes(Blueprint $blueprint): array
    {
        return [];
    }


    /**
     * Index names are scoped to the table on this driver, so the name the
     * source chose is already unique and is kept verbatim.
     */
    protected function qualifyIndexName(string $table, string $base): string
    {
        return $base;
    }

    /** Constraint names are table-scoped here too. */
    protected function qualifyConstraintName(string $table, string $base, string $prefix): string
    {
        return $base;
    }

    protected function autoIncrementKeyword(): string { return 'AUTO_INCREMENT'; }
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
