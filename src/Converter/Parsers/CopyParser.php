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
 * Read PostgreSQL's COPY bulk-load format.
 *
 * pg_dump writes table data as `COPY t (cols) FROM stdin;` followed by
 * tab-separated rows and a closing `\.` line, unless it was run with
 * --inserts. Without this the rows are not SQL at all and cannot be converted.
 */
final class CopyParser
{
    /**
     * Parse the header that opens a data block.
     *
     * @return ?array{table:string,columns:string[],text:bool}
     *         `text` is false for an explicit CSV/binary format, which must not
     *         be decoded with the text-format rules below.
     */
    public function header(Statement $statement): ?array
    {
        $sql = $statement->body();

        if (!preg_match('/^COPY\s+/i', $sql, $head)) {
            return null;
        }

        $pos   = strlen($head[0]);
        $table = SqlScanner::readIdentifier($sql, $pos);

        if ($table === null || $table === '') {
            return null;
        }

        $columns = [];

        // The column list is optional: "COPY t FROM stdin" is positional.
        if (preg_match('/\G\s*\(/', $sql, $m, 0, $pos)) {
            $open  = strpos($sql, '(', $pos);
            $close = $open === false ? null : SqlScanner::matchingParen($sql, $open);

            if ($open === false || $close === null) {
                return null;
            }

            foreach (SqlScanner::splitTopLevel(substr($sql, $open + 1, $close - $open - 1)) as $entry) {
                $entry = SqlScanner::unquoteIdentifier($entry);

                if ($entry !== '') {
                    $columns[] = $entry;
                }
            }

            $pos = $close + 1;
        }

        if (!preg_match('/\G\s*FROM\s+STDIN\b/i', $sql, $m, 0, $pos)) {
            return null;
        }

        $tail = substr($sql, $pos + strlen($m[0]));

        return [
            'table'   => $table,
            'columns' => $columns,
            'text'    => !preg_match('/\b(CSV|BINARY)\b/i', $tail),
        ];
    }

    /**
     * Split one data row into its field values.
     *
     * Text format: fields are tab-separated, an unescaped `\N` is NULL, and an
     * empty field is the empty string — which is emphatically not the same
     * thing. Everything else is backslash-escaped.
     *
     * @return array<int,?string> Null entries are SQL NULL.
     */
    public function fields(string $line): array
    {
        $fields = [];

        foreach (explode("\t", $line) as $field) {
            $fields[] = $field === '\\N' ? null : $this->unescape($field);
        }

        return $fields;
    }

    /** Undo the text-format backslash escapes. */
    private function unescape(string $field): string
    {
        if (!str_contains($field, '\\')) {
            return $field;
        }

        $out    = '';
        $length = strlen($field);

        for ($i = 0; $i < $length; $i++) {
            if ($field[$i] !== '\\' || $i + 1 >= $length) {
                $out .= $field[$i];
                continue;
            }

            $next = $field[++$i];

            switch ($next) {
                case 'b': $out .= "\x08"; break;
                case 'f': $out .= "\f";   break;
                case 'n': $out .= "\n";   break;
                case 'r': $out .= "\r";   break;
                case 't': $out .= "\t";   break;
                case 'v': $out .= "\x0B"; break;

                case 'x':
                    // \xH or \xHH
                    if (preg_match('/\G[0-9A-Fa-f]{1,2}/', $field, $m, 0, $i + 1)) {
                        $out .= chr((int) hexdec($m[0]));
                        $i   += strlen($m[0]);
                    } else {
                        $out .= 'x';
                    }
                    break;

                default:
                    // \0 to \377 octal, otherwise the character stands for itself
                    // (which covers the \\ case).
                    if (preg_match('/\G[0-7]{1,3}/', $field, $m, 0, $i)) {
                        $out .= chr((int) octdec($m[0]) & 0xFF);
                        $i   += strlen($m[0]) - 1;
                    } else {
                        $out .= $next;
                    }
            }
        }

        return $out;
    }
}
