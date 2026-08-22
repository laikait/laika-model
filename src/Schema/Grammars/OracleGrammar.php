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
 * Oracle DDL.
 *
 * Assumes Oracle 12c or later: identity columns arrived in 12.1 and the
 * 128-character identifier limit in 12.2. On 11g and older the identity clause
 * is a syntax error and a sequence plus a BEFORE INSERT trigger is the only
 * way to auto-number a column.
 *
 * Identifiers are quoted and their case is preserved, matching what
 * Model::wrapIdent() emits for this driver. That keeps the schema builder and
 * the query builder able to see the same tables — at the cost that an unquoted
 * `SELECT * FROM users` typed in SQL*Plus folds to USERS and finds nothing.
 */
class OracleGrammar extends Grammar
{
    protected function wrapColumn(string $col): string { return '"' . str_replace('"', '""', $col) . '"'; }
    protected function wrapTable(string $table): string { return '"' . str_replace('"', '""', $table) . '"'; }

    /** 128 from 12.2; 30 before that. The lower bound is the safe one. */
    protected function maxIdentifierLength(): ?int { return 30; }

    /**
     * Oracle's column_definition is `column datatype [DEFAULT expr]
     * [inline_constraint ...]`, and NOT NULL is an inline constraint — so
     * "NUMBER(1) NOT NULL DEFAULT 1" is a syntax error.
     */
    protected function defaultBeforeNullable(): bool { return true; }

    public function compileCreate(Blueprint $blueprint): string
    {
        $table   = $this->wrapTable($blueprint->getTable());
        $columns = array_map([$this, 'columnToSql'], $blueprint->getColumns());
        $lines   = array_merge($columns, $this->compileConstraints($blueprint));

        $sql = "CREATE TABLE {$table} (\n  " . implode(",\n  ", $lines) . "\n)";

        // Oracle has no CREATE TABLE IF NOT EXISTS. Wrapping the statement in a
        // block that swallows ORA-00955 (name already used) is the standard
        // stand-in.
        if ($blueprint->getOption('ifNotExists')) {
            return $this->ignoring($sql, -955);
        }

        return $sql . ';';
    }

    public function compileAddColumns(Blueprint $blueprint): string
    {
        $table  = $this->wrapTable($blueprint->getTable());
        $alters = array_map([$this, 'columnToSql'], $blueprint->getColumns());

        // ADD takes a parenthesised list here, not one ADD COLUMN per column.
        return "ALTER TABLE {$table} ADD (\n  " . implode(",\n  ", $alters) . "\n);";
    }

    public function compileDrop(string $table): string
    {
        return "DROP TABLE {$this->wrapTable($table)} CASCADE CONSTRAINTS;";
    }

    /**
     * Oracle has no DROP TABLE IF EXISTS, so this is an anonymous block that
     * swallows ORA-00942 (table or view does not exist).
     *
     * The block contains its own semicolons. Executing it through PDO is fine —
     * it is one statement to the server — but re-reading converter output that
     * contains it back through the Lexer would split it in the wrong places:
     * the Lexer understands PostgreSQL dollar-quoting, not PL/SQL blocks.
     */
    public function compileDropIfExists(string $table): string
    {
        return $this->ignoring("DROP TABLE {$this->wrapTable($table)} CASCADE CONSTRAINTS", -942);
    }

    public function compileTableExists(): string
    {
        return 'SELECT COUNT(*) FROM USER_TABLES WHERE TABLE_NAME = ?';
    }

    public function compileColumnExists(): string
    {
        return 'SELECT COUNT(*) FROM USER_TAB_COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?';
    }

    public function compileRenameTable(string $from, string $to): string
    {
        return "ALTER TABLE {$this->wrapTable($from)} RENAME TO {$this->wrapTable($to)};";
    }

    /**
     * Run $sql, ignoring one specific ORA- error code.
     *
     * The single quotes in the statement have to be doubled: it is being
     * embedded in a PL/SQL string literal.
     */
    private function ignoring(string $sql, int $code): string
    {
        $quoted = str_replace("'", "''", $sql);

        return "BEGIN EXECUTE IMMEDIATE '{$quoted}';"
            . " EXCEPTION WHEN OTHERS THEN IF SQLCODE != {$code} THEN RAISE; END IF; END;";
    }

    // -----------------------------------------------------------------------
    // Types
    // -----------------------------------------------------------------------

    /**
     * Identity is folded into the type rather than returned from
     * autoIncrementKeyword(), because columnToSql() appends that keyword after
     * NOT NULL — and Oracle requires the identity clause to come before any
     * inline constraint. Same trick PgSqlGrammar uses for SERIAL.
     */
    protected function typeId(array $col): string        { return 'NUMBER(10) GENERATED BY DEFAULT AS IDENTITY'; }
    protected function typeBigId(array $col): string     { return 'NUMBER(19) GENERATED BY DEFAULT AS IDENTITY'; }
    protected function autoIncrementKeyword(): string    { return ''; }

    protected function typeInteger(array $col): string      { return 'NUMBER(10)'; }
    protected function typeBigInteger(array $col): string   { return 'NUMBER(19)'; }
    protected function typeSmallInteger(array $col): string { return 'NUMBER(5)'; }
    protected function typeTinyInteger(array $col): string  { return 'NUMBER(3)'; }

    /** No SQL-level BOOLEAN before 23c; NUMBER(1) is the long-standing stand-in. */
    protected function typeBoolean(array $col): string      { return 'NUMBER(1)'; }

    protected function typeFloat(array $col): string        { return 'BINARY_FLOAT'; }
    protected function typeDouble(array $col): string       { return 'BINARY_DOUBLE'; }

    protected function typeString(array $col): string       { return 'VARCHAR2(' . ($col['length'] ?? 255) . ')'; }
    protected function typeChar(array $col): string         { return 'CHAR(' . ($col['length'] ?? 36) . ')'; }
    protected function typeUid(array $col): string          { return 'VARCHAR2(38)'; }

    protected function typeText(array $col): string         { return 'CLOB'; }
    protected function typeMediumText(array $col): string   { return 'CLOB'; }
    protected function typeLongText(array $col): string     { return 'CLOB'; }
    protected function typeJson(array $col): string         { return 'CLOB'; }

    protected function typeTinyBlob(array $col): string     { return 'BLOB'; }
    protected function typeBlob(array $col): string         { return 'BLOB'; }
    protected function typeMediumBlob(array $col): string   { return 'BLOB'; }
    protected function typeLongBlob(array $col): string     { return 'BLOB'; }
    protected function typeBinary(array $col): string       { return 'BLOB'; }

    /** Oracle has no TIME type; TIMESTAMP is the closest thing. */
    protected function typeTime(array $col): string         { return 'TIMESTAMP'; }
    protected function typeDateTime(array $col): string     { return 'TIMESTAMP'; }
    protected function typeTimestamp(array $col): string    { return 'TIMESTAMP'; }
}
