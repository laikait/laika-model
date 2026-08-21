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
 * Re-express a value literal for the target dialect.
 *
 * This is where silent data corruption hides. Every case below produces SQL
 * that is *valid* in the target but stores the *wrong bytes* if left alone:
 *
 *  - MySQL escapes with backslashes; PostgreSQL reads a backslash literally in
 *    a standard-conforming string, so 'a\'b' must become 'a''b'.
 *  - 0xDEADBEEF is a blob literal in MySQL, a number nowhere else.
 *  - 0 and 1 into a PostgreSQL BOOLEAN column are a type error, not false/true.
 *  - '0000-00-00' is a legal MySQL date and is rejected by PostgreSQL.
 */
final class LiteralTranslator
{
    /** MySQL's backslash escape sequences and the bytes they stand for. */
    private const MYSQL_ESCAPES = [
        '\\0'  => "\0",
        '\\b'  => "\x08",
        '\\n'  => "\n",
        '\\r'  => "\r",
        '\\t'  => "\t",
        '\\Z'  => "\x1A",
        '\\\\' => '\\',
        "\\'"  => "'",
        '\\"'  => '"',
        '\\%'  => '\\%',
        '\\_'  => '\\_',
    ];

    public function __construct(
        private readonly string $from,
        private readonly string $to,
        private readonly Report $report,
    ) {}

    /**
     * Translate one raw value token.
     *
     * @param ?string $columnType Canonical type of the target column, when
     *                            known; drives the boolean and blob handling.
     */
    public function translate(string $value, ?string $columnType, int $ordinal): string
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strcasecmp($trimmed, 'NULL') === 0) {
            return 'NULL';
        }

        // Hex blob literal (MySQL / SQL Server).
        if (preg_match('/^0x([0-9A-Fa-f]*)$/', $trimmed, $m)) {
            return $this->hexLiteral($m[1]);
        }

        // MySQL bit literal.
        if (preg_match("/^b'([01]*)'$/i", $trimmed, $m)) {
            return $this->bitLiteral($m[1], $columnType);
        }

        if ($this->isQuotedString($trimmed)) {
            return $this->stringLiteral($trimmed, $columnType, $ordinal);
        }

        if (is_numeric($trimmed)) {
            return $this->numericLiteral($trimmed, $columnType);
        }

        // Keywords and function calls (CURRENT_TIMESTAMP, NOW(), DEFAULT).
        return $trimmed;
    }

    /** Decode a source string literal and re-encode it for the target. */
    private function stringLiteral(string $literal, ?string $columnType, int $ordinal): string
    {
        $raw = $this->decode($literal);

        // A zero date is legal in MySQL and rejected outright by PostgreSQL.
        if (self::isZeroDate($raw) && $this->to !== 'mysql') {
            $this->report->warn(
                $ordinal,
                "Zero date [{$raw}] has no equivalent in [{$this->to}]; written as NULL.",
                Warning::LEVEL_LOSSY
            );

            return 'NULL';
        }

        if ($columnType === 'boolean') {
            return $this->booleanLiteral($raw === '1' || strcasecmp($raw, 'true') === 0);
        }

        return $this->encode($raw);
    }

    /**
     * Decode a source literal to its raw bytes.
     *
     * Doubling ('') applies in every dialect; backslash escapes only in MySQL,
     * and in PostgreSQL only inside an E'...' string.
     */
    public function decode(string $literal): string
    {
        $escapeString = false;

        if (preg_match("/^[EeNn]'/", $literal)) {
            $escapeString = $literal[0] === 'E' || $literal[0] === 'e';
            $literal      = substr($literal, 1);
        }

        $inner = substr($literal, 1, -1);
        $inner = str_replace("''", "'", $inner);

        if ($this->from === 'mysql' || $escapeString) {
            $inner = strtr($inner, self::MYSQL_ESCAPES);
        }

        return $inner;
    }

    /** Encode raw bytes as a literal the target will read back identically. */
    public function encode(string $raw): string
    {
        // Standard SQL doubling works in every supported target, and avoids
        // depending on the target's backslash setting.
        $escaped = str_replace("'", "''", $raw);

        // Bytes with no printable form need an escape string in PostgreSQL.
        if ($this->to === 'pgsql' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $escaped)) {
            return "E'" . addcslashes($escaped, "\0..\10\13\14\16..\37\\") . "'";
        }

        if ($this->to === 'mysql') {
            // Keep binary-safe by escaping the bytes MySQL treats specially.
            $escaped = str_replace(['\\', "\0"], ['\\\\', '\\0'], $escaped);
        }

        return "'" . $escaped . "'";
    }

    /** 0xDEADBEEF in the target's spelling. */
    private function hexLiteral(string $hex): string
    {
        return match ($this->to) {
            'mysql', 'sqlsrv' => '0x' . $hex,
            'sqlite'          => "X'" . $hex . "'",
            'pgsql'           => "'\\x" . strtolower($hex) . "'::bytea",
            default           => "X'" . $hex . "'",
        };
    }

    /** MySQL b'0' / b'1'. */
    private function bitLiteral(string $bits, ?string $columnType): string
    {
        $value = $bits === '' ? 0 : (int) base_convert($bits, 2, 10);

        if ($columnType === 'boolean') {
            return $this->booleanLiteral($value !== 0);
        }

        return (string) $value;
    }

    /**
     * A numeric literal going into a boolean column.
     *
     * PostgreSQL rejects `0` for a BOOLEAN outright; SQL Server's BIT accepts
     * it, and SQLite stores 0/1 anyway.
     */
    private function numericLiteral(string $value, ?string $columnType): string
    {
        if ($columnType === 'boolean' && $this->to === 'pgsql') {
            return $this->booleanLiteral((float) $value !== 0.0);
        }

        return $value;
    }

    private function booleanLiteral(bool $value): string
    {
        return match ($this->to) {
            'pgsql' => $value ? 'TRUE' : 'FALSE',
            default => $value ? '1' : '0',
        };
    }

    private function isQuotedString(string $value): bool
    {
        return (bool) preg_match("/^[EeNn]?'.*'$/s", $value);
    }

    /**
     * MySQL's zero date. Public and static because BlueprintBuilder needs the
     * same test for column DEFAULTs — one definition, two callers.
     */
    public static function isZeroDate(string $value): bool
    {
        return (bool) preg_match('/^0000-00-00(\s+00:00:00(\.0+)?)?$/', $value);
    }
}
