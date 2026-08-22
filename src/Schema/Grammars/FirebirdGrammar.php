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

use Laika\Model\Exceptions\SchemaException;
use Laika\Model\Schema\Blueprint;

/**
 * Firebird DDL.
 *
 * Assumes Firebird 3.0 or later: identity columns and a native BOOLEAN both
 * arrived there. On 2.5 and older a generator plus a BEFORE INSERT trigger is
 * the only way to auto-number a column, and BOOLEAN has to be SMALLINT.
 *
 * Identifiers are quoted and their case is preserved, matching what
 * Model::wrapIdent() emits for this driver — see OracleGrammar for why that
 * matters and what it costs.
 */
class FirebirdGrammar extends Grammar
{
    protected function wrapColumn(string $col): string { return '"' . str_replace('"', '""', $col) . '"'; }
    protected function wrapTable(string $table): string { return '"' . str_replace('"', '""', $table) . '"'; }

    /** 63 from Firebird 4.0, but only 31 on 3.x. The lower bound is the safe one. */
    protected function maxIdentifierLength(): ?int { return 31; }

    /** Firebird's column definition is `<type> [DEFAULT x] [NOT NULL]`. */
    protected function defaultBeforeNullable(): bool { return true; }

    /**
     * Firebird has no explicit NULL keyword in a column definition — a column
     * is nullable by not saying NOT NULL. Emitting "VARCHAR(50) NULL" is a
     * syntax error there.
     */
    protected function nullableClause(bool $nullable): string
    {
        return $nullable ? '' : ' NOT NULL';
    }

    public function compileCreate(Blueprint $blueprint): string
    {
        $table   = $this->wrapTable($blueprint->getTable());
        $columns = array_map([$this, 'columnToSql'], $blueprint->getColumns());
        $lines   = array_merge($columns, $this->compileConstraints($blueprint));

        $sql = "CREATE TABLE {$table} (\n  " . implode(",\n  ", $lines) . "\n)";

        if ($blueprint->getOption('ifNotExists')) {
            return $this->guarded($sql, $blueprint->getTable(), false);
        }

        return $sql . ';';
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

    /**
     * Firebird has no DROP TABLE IF EXISTS, so this is an EXECUTE BLOCK guarded
     * on RDB$RELATIONS.
     *
     * As with Oracle, the block carries its own semicolons: fine through PDO as
     * a single statement, but the Lexer would mis-split it if converter output
     * containing one were read back in.
     */
    public function compileDropIfExists(string $table): string
    {
        return $this->guarded("DROP TABLE {$this->wrapTable($table)}", $table, true);
    }

    public function compileTableExists(): string
    {
        return 'SELECT COUNT(*) FROM RDB$RELATIONS WHERE TRIM(RDB$RELATION_NAME) = ?';
    }

    public function compileColumnExists(): string
    {
        return 'SELECT COUNT(*) FROM RDB$RELATION_FIELDS'
            . ' WHERE TRIM(RDB$RELATION_NAME) = ? AND TRIM(RDB$FIELD_NAME) = ?';
    }

    /**
     * Firebird cannot rename a table. There is no ALTER TABLE ... RENAME TO,
     * and no other statement does it either — the documented workaround is to
     * recreate the table and copy the rows, which is a migration rather than
     * something this method could return.
     */
    public function compileRenameTable(string $from, string $to): string
    {
        throw new SchemaException(
            "Firebird cannot rename a table [{$from}] to [{$to}]. "
            . 'Create the new table, copy the rows across and drop the old one.'
        );
    }

    /**
     * Run $sql only when the table's presence in RDB$RELATIONS matches $exists.
     *
     * The single quotes in the statement are doubled: it is being embedded in a
     * PSQL string literal.
     */
    private function guarded(string $sql, string $table, bool $exists): string
    {
        $quoted    = str_replace("'", "''", $sql);
        $name      = str_replace("'", "''", $table);
        $condition = $exists ? 'EXISTS' : 'NOT EXISTS';

        return "EXECUTE BLOCK AS BEGIN"
            . " IF ({$condition}(SELECT 1 FROM RDB\$RELATIONS WHERE TRIM(RDB\$RELATION_NAME) = '{$name}'))"
            . " THEN EXECUTE STATEMENT '{$quoted}';"
            . ' END';
    }

    // -----------------------------------------------------------------------
    // Types
    // -----------------------------------------------------------------------

    /** Folded into the type for the same reason as Oracle — see OracleGrammar. */
    protected function typeId(array $col): string        { return 'INTEGER GENERATED BY DEFAULT AS IDENTITY'; }
    protected function typeBigId(array $col): string     { return 'BIGINT GENERATED BY DEFAULT AS IDENTITY'; }
    protected function autoIncrementKeyword(): string    { return ''; }

    protected function typeInteger(array $col): string      { return 'INTEGER'; }
    protected function typeBigInteger(array $col): string   { return 'BIGINT'; }
    protected function typeSmallInteger(array $col): string { return 'SMALLINT'; }

    /** No TINYINT; SMALLINT is the narrowest integer Firebird has. */
    protected function typeTinyInteger(array $col): string  { return 'SMALLINT'; }

    /** Native since 3.0. */
    protected function typeBoolean(array $col): string      { return 'BOOLEAN'; }

    protected function typeFloat(array $col): string        { return 'FLOAT'; }
    protected function typeDouble(array $col): string       { return 'DOUBLE PRECISION'; }

    protected function typeString(array $col): string       { return 'VARCHAR(' . ($col['length'] ?? 255) . ')'; }
    protected function typeChar(array $col): string         { return 'CHAR(' . ($col['length'] ?? 36) . ')'; }
    protected function typeUid(array $col): string          { return 'CHAR(38)'; }

    protected function typeText(array $col): string         { return 'BLOB SUB_TYPE TEXT'; }
    protected function typeMediumText(array $col): string   { return 'BLOB SUB_TYPE TEXT'; }
    protected function typeLongText(array $col): string     { return 'BLOB SUB_TYPE TEXT'; }
    protected function typeJson(array $col): string         { return 'BLOB SUB_TYPE TEXT'; }

    protected function typeTinyBlob(array $col): string     { return 'BLOB'; }
    protected function typeBlob(array $col): string         { return 'BLOB'; }
    protected function typeMediumBlob(array $col): string   { return 'BLOB'; }
    protected function typeLongBlob(array $col): string     { return 'BLOB'; }
    protected function typeBinary(array $col): string       { return 'BLOB'; }

    protected function typeDateTime(array $col): string     { return 'TIMESTAMP'; }
}
