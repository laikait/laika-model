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

class SqlSrvGrammar extends Grammar
{
    protected function wrapColumn(string $col): string  { return '[' . str_replace(']', ']]', $col) . ']'; }
    protected function wrapTable(string $table): string { return '[' . str_replace(']', ']]', $table) . ']'; }

    public function compileCreate(Blueprint $blueprint): string
    {
        $table   = $this->wrapTable($blueprint->getTable());
        $columns = array_map([$this, 'columnToSql'], $blueprint->getColumns());
        $lines   = array_merge($columns, $this->compileConstraints($blueprint));

        if ($blueprint->getOption('ifNotExists')) {
            $check = "IF NOT EXISTS (SELECT * FROM sysobjects WHERE name='{$blueprint->getTable()}' AND xtype='U')\n";
            return "{$check}CREATE TABLE {$table} (\n  " . implode(",\n  ", $lines) . "\n);";
        }

        return "CREATE TABLE {$table} (\n  " . implode(",\n  ", $lines) . "\n);";
    }

    public function compileAddColumns(Blueprint $blueprint): string
    {
        $table  = $this->wrapTable($blueprint->getTable());
        $alters = array_map(
            fn($col) => 'ADD ' . $this->columnToSql($col),
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
        $t = addslashes($table);
        return "IF OBJECT_ID(N'{$t}', N'U') IS NOT NULL DROP TABLE {$this->wrapTable($table)};";
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
        return "EXEC sp_rename '{$from}', '{$to}';";
    }

    protected function typeId(array $col): string           { return 'INT'; }
    protected function typeBigId(array $col): string        { return 'BIGINT'; }
    protected function typeBoolean(array $col): string      { return 'BIT'; }
    protected function typeDateTime(array $col): string     { return 'DATETIME2'; }
    protected function typeJson(array $col): string         { return 'NVARCHAR(MAX)'; }
    protected function typeLongText(array $col): string     { return 'NVARCHAR(MAX)'; }
    protected function typeMediumText(array $col): string   { return 'NVARCHAR(MAX)'; }
    protected function typeText(array $col): string         { return 'NVARCHAR(MAX)'; }
    protected function typeString(array $col): string       { return 'NVARCHAR(' . ($col['length'] ?? 255) . ')'; }
    protected function typeBinary(array $col): string       { return 'VARBINARY(MAX)'; }
    protected function typeUid(array $col): string          { return 'UNIQUEIDENTIFIER'; }

    /**
     * Index names are scoped to the table on this driver, so the name the
     * source chose is already unique and is kept verbatim.
     */
    protected function qualifyIndexName(string $table, string $base): string
    {
        return $base;
    }

    protected function autoIncrementKeyword(): string       { return 'IDENTITY(1,1)'; }

    /** T-SQL has no DOUBLE; FLOAT(53) is the 8-byte equivalent. */
    protected function typeDouble(array $col): string       { return 'FLOAT(53)'; }

    /**
     * In T-SQL a bare TIMESTAMP column is a `rowversion` — an auto-generated
     * binary counter, not a point in time. DATETIME2 is the real equivalent.
     */
    protected function typeTimestamp(array $col): string    { return 'DATETIME2'; }
    protected function typeTinyBlob(array $col): string     { return 'VARBINARY(MAX)'; }
    protected function typeBlob(array $col): string         { return 'VARBINARY(MAX)'; }
    protected function typeMediumBlob(array $col): string   { return 'VARBINARY(MAX)'; }
    protected function typeLongBlob(array $col): string     { return 'VARBINARY(MAX)'; }
}
