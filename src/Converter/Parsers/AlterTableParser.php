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

namespace Laika\Model\Converter\Parsers;

use Laika\Model\Converter\SqlScanner;
use Laika\Model\Converter\Statement;

/**
 * Pull the meaning out of an ALTER TABLE.
 *
 * This exists because pg_dump does not write a self-contained CREATE TABLE the
 * way mysqldump does. It emits a bare table and then reattaches the primary
 * key, the unique constraints and the identity through trailing ALTER TABLE
 * statements. Ignoring them would produce a target table with no keys at all.
 *
 * Only the forms a dump actually contains are recognised. Anything else returns
 * null so the caller can fall back to passing the statement through unchanged
 * rather than quietly discarding something it did not understand.
 */
final class AlterTableParser
{
    public const ADD_PRIMARY_KEY = 'primary_key';
    public const ADD_UNIQUE      = 'unique';
    public const ADD_FOREIGN_KEY = 'foreign_key';
    public const AUTO_INCREMENT  = 'auto_increment';
    public const DROPPABLE       = 'droppable';

    /**
     * @return ?array{
     *     form:string,
     *     table:string,
     *     name:?string,
     *     columns:string[],
     *     column:?string,
     *     references:?array{table:string,columns:string[]},
     *     actions:string
     * }
     */
    public function parse(Statement $statement): ?array
    {
        $sql = $statement->body();

        // ONLY is PostgreSQL's "do not cascade to inheriting tables". There is
        // no inheritance on any target, so it is noise here.
        if (!preg_match('/^ALTER\s+TABLE\s+(ONLY\s+)?/i', $sql, $head)) {
            return null;
        }

        $pos   = strlen($head[0]);
        $table = SqlScanner::readIdentifier($sql, $pos);

        if ($table === null || $table === '') {
            return null;
        }

        // Ownership is a PostgreSQL role grant; the target has its own.
        if (preg_match('/\G\s*OWNER\s+TO\b/i', $sql, $m, 0, $pos)) {
            return $this->result(self::DROPPABLE, $table);
        }

        if (preg_match('/\G\s*ALTER\s+(COLUMN\s+)?/i', $sql, $m, 0, $pos)) {
            return $this->parseAlterColumn($sql, $pos + strlen($m[0]), $table);
        }

        if (preg_match('/\G\s*ADD\s+(CONSTRAINT\s+)?/i', $sql, $m, 0, $pos)) {
            return $this->parseAddConstraint($sql, $pos + strlen($m[0]), $table, trim($m[1] ?? '') !== '');
        }

        return null;
    }

    /**
     * `ALTER COLUMN x SET DEFAULT nextval('seq'::regclass)` is how pg_dump
     * spells a serial column. Any other ALTER COLUMN is left alone.
     */
    private function parseAlterColumn(string $sql, int $pos, string $table): ?array
    {
        $column = SqlScanner::readIdentifier($sql, $pos);

        if ($column === null || $column === '') {
            return null;
        }

        if (!preg_match('/\G\s*SET\s+DEFAULT\s+nextval\s*\(/i', $sql, $m, 0, $pos)) {
            return null;
        }

        return $this->result(self::AUTO_INCREMENT, $table, column: $column);
    }

    private function parseAddConstraint(string $sql, int $pos, string $table, bool $named): ?array
    {
        $name = null;

        if ($named) {
            $name = SqlScanner::readIdentifier($sql, $pos);
        }

        if (preg_match('/\G\s*PRIMARY\s+KEY\s*/i', $sql, $m, 0, $pos)) {
            $columns = $this->columnList($sql, $pos + strlen($m[0]));

            return $columns === null
                ? null
                : $this->result(self::ADD_PRIMARY_KEY, $table, $name, $columns);
        }

        if (preg_match('/\G\s*UNIQUE\s*/i', $sql, $m, 0, $pos)) {
            $columns = $this->columnList($sql, $pos + strlen($m[0]));

            return $columns === null
                ? null
                : $this->result(self::ADD_UNIQUE, $table, $name, $columns);
        }

        if (preg_match('/\G\s*FOREIGN\s+KEY\s*/i', $sql, $m, 0, $pos)) {
            return $this->parseForeignKey($sql, $pos + strlen($m[0]), $table, $name);
        }

        // CHECK and EXCLUDE constraints carry expressions in the source
        // dialect's own syntax; translating those is out of scope.
        return null;
    }

    private function parseForeignKey(string $sql, int $pos, string $table, ?string $name): ?array
    {
        $columns = $this->columnList($sql, $pos, $pos);

        if ($columns === null) {
            return null;
        }

        if (!preg_match('/\G\s*REFERENCES\s+/i', $sql, $m, 0, $pos)) {
            return null;
        }

        $pos          += strlen($m[0]);
        $foreignTable  = SqlScanner::readIdentifier($sql, $pos);

        if ($foreignTable === null || $foreignTable === '') {
            return null;
        }

        $foreignColumns = $this->columnList($sql, $pos, $pos) ?? [];

        // ON DELETE / ON UPDATE / DEFERRABLE are portable enough to carry over
        // verbatim; whatever is left after the reference list is the tail.
        $actions = trim(rtrim(substr($sql, $pos), "; \n\r\t"));

        return $this->result(
            self::ADD_FOREIGN_KEY,
            $table,
            $name,
            $columns,
            references: ['table' => $foreignTable, 'columns' => $foreignColumns],
            actions: $actions
        );
    }

    /**
     * Read a parenthesised identifier list starting at or after $pos.
     *
     * @param ?int $end Set to the offset just past the closing paren.
     * @return ?string[]
     */
    private function columnList(string $sql, int $pos, ?int &$end = null): ?array
    {
        $open = strpos($sql, '(', $pos);

        if ($open === false) {
            return null;
        }

        $close = SqlScanner::matchingParen($sql, $open);

        if ($close === null) {
            return null;
        }

        $columns = [];

        foreach (SqlScanner::splitTopLevel(substr($sql, $open + 1, $close - $open - 1)) as $entry) {
            $entry = SqlScanner::unquoteIdentifier($entry);

            if ($entry !== '') {
                $columns[] = $entry;
            }
        }

        $end = $close + 1;

        return $columns === [] ? null : $columns;
    }

    /**
     * @param string[] $columns
     * @param ?array{table:string,columns:string[]} $references
     */
    private function result(
        string $form,
        string $table,
        ?string $name = null,
        array $columns = [],
        ?string $column = null,
        ?array $references = null,
        string $actions = ''
    ): array {
        return [
            'form'       => $form,
            'table'      => $table,
            'name'       => $name,
            'columns'    => $columns,
            'column'     => $column,
            'references' => $references,
            'actions'    => $actions,
        ];
    }
}
