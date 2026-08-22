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

namespace Laika\Model\Converter;

/**
 * Small quote- and paren-aware helpers shared by the parsers.
 *
 * The Lexer solves this problem for statement boundaries; these solve the same
 * problem one level down, inside a single statement — splitting a column list
 * on commas, or finding the closing paren of a CREATE TABLE body, without being
 * fooled by a comma or paren inside a string literal.
 */
final class SqlScanner
{
    /** Identifier quote characters across the supported dialects. */
    private const OPENERS = ['`' => '`', '"' => '"', '[' => ']', "'" => "'"];

    /**
     * Split on a single-character separator found at paren depth zero and
     * outside any quoted region.
     *
     * @return string[] Trimmed parts; empty parts are dropped.
     */
    public static function splitTopLevel(string $sql, string $separator = ','): array
    {
        $parts  = [];
        $depth  = 0;
        $start  = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if (isset(self::OPENERS[$char])) {
                $i = self::skipQuoted($sql, $i);
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                continue;
            }

            if ($char === $separator && $depth === 0) {
                $parts[] = trim(substr($sql, $start, $i - $start));
                $start   = $i + 1;
            }
        }

        $parts[] = trim(substr($sql, $start));

        return array_values(array_filter($parts, fn(string $p): bool => $p !== ''));
    }

    /**
     * Offset of the ')' matching the '(' at $open, or null if unbalanced.
     */
    public static function matchingParen(string $sql, int $open): ?int
    {
        $depth  = 0;
        $length = strlen($sql);

        for ($i = $open; $i < $length; $i++) {
            $char = $sql[$i];

            if (isset(self::OPENERS[$char])) {
                $i = self::skipQuoted($sql, $i);
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Advance past the quoted region starting at $i.
     *
     * Handles doubled terminators ('' `` ]] "") and backslash escapes.
     *
     * @return int Offset of the closing quote (the caller's loop increments).
     */
    public static function skipQuoted(string $sql, int $i): int
    {
        $open   = $sql[$i];
        $close  = self::OPENERS[$open];
        $length = strlen($sql);

        for ($j = $i + 1; $j < $length; $j++) {
            if ($sql[$j] === '\\' && $open === "'") {
                $j++;
                continue;
            }

            if ($sql[$j] !== $close) {
                continue;
            }

            // Doubled terminator is a literal.
            if ($j + 1 < $length && $sql[$j + 1] === $close) {
                $j++;
                continue;
            }

            return $j;
        }

        return $length - 1;
    }

    /**
     * Read an identifier at $pos, advancing it past what was consumed.
     *
     * Understands `backticks`, "double quotes", [brackets] and bare words, and
     * keeps schema qualification (`db`.`table`) together.
     */
    public static function readIdentifier(string $sql, int &$pos): ?string
    {
        $length = strlen($sql);

        while ($pos < $length && self::isSpace($sql[$pos])) {
            $pos++;
        }

        if ($pos >= $length) {
            return null;
        }

        $parts = [];

        while ($pos < $length) {
            $char = $sql[$pos];

            if (isset(self::OPENERS[$char]) && $char !== "'") {
                $end     = self::skipQuoted($sql, $pos);
                $raw     = substr($sql, $pos + 1, $end - $pos - 1);
                $parts[] = str_replace(str_repeat(self::OPENERS[$char], 2), self::OPENERS[$char], $raw);
                $pos     = $end + 1;
            } else {
                $matched = preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z0-9_$\x80-\xff]*/', $sql, $m, 0, $pos);

                if ($matched !== 1) {
                    break;
                }

                $parts[] = $m[0];
                $pos    += strlen($m[0]);
            }

            // Schema qualification continues the identifier.
            if ($pos < $length && $sql[$pos] === '.') {
                $pos++;
                continue;
            }

            break;
        }

        if ($parts === []) {
            return null;
        }

        // Keep only the final part: the converter writes into the target's
        // default schema, and grammars have no schema-qualification support.
        return end($parts);
    }

    /** Strip surrounding quotes from an already-isolated identifier token. */
    public static function unquoteIdentifier(string $token): string
    {
        $token = trim($token);

        if ($token === '') {
            return $token;
        }

        $open = $token[0];

        if (!isset(self::OPENERS[$open]) || $open === "'") {
            return $token;
        }

        $close = self::OPENERS[$open];

        if (!str_ends_with($token, $close)) {
            return $token;
        }

        return str_replace($close . $close, $close, substr($token, 1, -1));
    }

    /** Unwrap a single-quoted SQL string literal to its raw value. */
    public static function unquoteString(string $token): string
    {
        $token = trim($token);

        if (strlen($token) < 2 || $token[0] !== "'" || !str_ends_with($token, "'")) {
            return $token;
        }

        return str_replace("''", "'", substr($token, 1, -1));
    }

    private static function isSpace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r";
    }
}
