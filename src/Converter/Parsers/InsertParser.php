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

use Laika\Model\Converter\Report;
use Laika\Model\Converter\SqlScanner;
use Laika\Model\Converter\Statement;
use Laika\Model\Converter\Warning;

/**
 * INSERT -> table, column list and row tuples.
 *
 * mysqldump writes extended inserts — one statement carrying thousands of rows
 * — so the tuples are yielded one at a time rather than materialised as a list.
 */
final class InsertParser
{
    public function __construct(private readonly Report $report) {}

    /**
     * @return ?array{table:string,columns:string[],values:int,ignore:bool}
     *         `values` is the byte offset of the first '(' after VALUES.
     */
    public function parse(Statement $statement): ?array
    {
        $sql = $statement->body();

        if (!preg_match('/^(INSERT|REPLACE)\s+/i', $sql, $head)) {
            return null;
        }

        $pos    = strlen($head[0]);
        $ignore = false;

        // INSERT [LOW_PRIORITY|DELAYED|HIGH_PRIORITY] [IGNORE] INTO
        while (preg_match('/\G(LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+/i', $sql, $m, 0, $pos)) {
            $ignore = $ignore || strcasecmp(trim($m[1]), 'IGNORE') === 0;
            $pos   += strlen($m[0]);
        }

        if (preg_match('/\GINTO\s+/i', $sql, $m, 0, $pos)) {
            $pos += strlen($m[0]);
        }

        $table = SqlScanner::readIdentifier($sql, $pos);

        if ($table === null || $table === '') {
            $this->report->warn(
                $statement->ordinal,
                'INSERT with no readable table name; passed through unchanged.',
                Warning::LEVEL_PASSTHROUGH
            );

            return null;
        }

        $columns = [];

        // Optional column list.
        $next = $this->skipSpace($sql, $pos);

        if ($next < strlen($sql) && $sql[$next] === '(') {
            $close = SqlScanner::matchingParen($sql, $next);

            if ($close === null) {
                $this->report->warn(
                    $statement->ordinal,
                    "INSERT INTO [{$table}]: unbalanced column list; passed through unchanged.",
                    Warning::LEVEL_PASSTHROUGH
                );

                return null;
            }

            foreach (SqlScanner::splitTopLevel(substr($sql, $next + 1, $close - $next - 1)) as $entry) {
                $columns[] = SqlScanner::unquoteIdentifier($entry);
            }

            $pos = $close + 1;
        }

        if (!preg_match('/\s*VALUES?\s*/i', $sql, $m, PREG_OFFSET_CAPTURE, $pos)) {
            // INSERT ... SELECT and INSERT ... SET have no tuple list.
            $this->report->warn(
                $statement->ordinal,
                "INSERT INTO [{$table}]: only VALUES form is supported; passed through unchanged.",
                Warning::LEVEL_PASSTHROUGH
            );

            return null;
        }

        return [
            'table'   => $table,
            'columns' => $columns,
            'values'  => $m[0][1] + strlen($m[0][0]),
            'ignore'  => $ignore,
        ];
    }

    /**
     * Yield each row tuple as a list of raw value tokens.
     *
     * @return \Generator<int,string[]>
     */
    public function tuples(Statement $statement, int $offset): \Generator
    {
        $sql    = $statement->body();
        $length = strlen($sql);
        $i      = $offset;

        while ($i < $length) {
            $i = $this->skipSpace($sql, $i);

            if ($i >= $length) {
                return;
            }

            if ($sql[$i] === ',') {
                $i++;
                continue;
            }

            if ($sql[$i] !== '(') {
                // Trailing clause such as ON DUPLICATE KEY UPDATE.
                if (preg_match('/\GON\s+(DUPLICATE|CONFLICT)\b/i', $sql, $m, 0, $i)) {
                    $this->report->warn(
                        $statement->ordinal,
                        'Upsert clause [' . trim($m[0]) . '] dropped — no portable equivalent.',
                        Warning::LEVEL_LOSSY
                    );
                }

                return;
            }

            $close = SqlScanner::matchingParen($sql, $i);

            if ($close === null) {
                $this->report->warn(
                    $statement->ordinal,
                    'Unbalanced row tuple in INSERT; remaining rows skipped.',
                    Warning::LEVEL_LOSSY
                );

                return;
            }

            yield SqlScanner::splitTopLevel(substr($sql, $i + 1, $close - $i - 1));

            $i = $close + 1;
        }
    }

    private function skipSpace(string $sql, int $i): int
    {
        $length = strlen($sql);

        while ($i < $length && ($sql[$i] === ' ' || $sql[$i] === "\t" || $sql[$i] === "\n" || $sql[$i] === "\r")) {
            $i++;
        }

        return $i;
    }
}
