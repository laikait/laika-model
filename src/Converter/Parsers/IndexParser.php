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
 * Standalone CREATE INDEX -> table, name and columns.
 *
 * pg_dump and sqlite dumps emit indexes as separate statements rather than
 * inline, so they arrive here rather than through CreateTableParser.
 */
final class IndexParser
{
    /**
     * @return ?array{table:string,name:?string,columns:string[],unique:bool}
     */
    public function parse(Statement $statement): ?array
    {
        $sql = $statement->body();

        if (!preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+/i', $sql, $head)) {
            return null;
        }

        $unique = trim($head[1] ?? '') !== '';
        $pos    = strlen($head[0]);

        // Some dialects allow IF NOT EXISTS here.
        if (preg_match('/\GIF\s+NOT\s+EXISTS\s+/i', $sql, $m, 0, $pos)) {
            $pos += strlen($m[0]);
        }

        $name = SqlScanner::readIdentifier($sql, $pos);

        if (!preg_match('/\G\s*ON\s+/i', $sql, $m, 0, $pos)) {
            return null;
        }

        $pos  += strlen($m[0]);
        $table = SqlScanner::readIdentifier($sql, $pos);

        if ($table === null || $table === '') {
            return null;
        }

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
            $entryPos = 0;
            $column   = SqlScanner::readIdentifier($entry, $entryPos);

            if ($column !== null && $column !== '') {
                $columns[] = $column;
            }
        }

        if ($columns === []) {
            return null;
        }

        return [
            'table'   => $table,
            'name'    => $name,
            'columns' => $columns,
            'unique'  => $unique,
        ];
    }
}
