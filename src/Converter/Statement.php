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
 * One SQL statement lifted out of the input, with enough provenance to point a
 * warning back at it.
 */
final class Statement
{
    public const KIND_CREATE_TABLE = 'create_table';
    public const KIND_CREATE_INDEX = 'create_index';
    public const KIND_INSERT       = 'insert';
    public const KIND_ALTER_TABLE  = 'alter_table';
    public const KIND_DROP_TABLE   = 'drop_table';
    public const KIND_SET          = 'set';
    public const KIND_TRANSACTION  = 'transaction';
    public const KIND_COMMENT      = 'comment';

    /** LOCK TABLES / FLUSH / ANALYZE — server housekeeping, not schema or data. */
    public const KIND_MAINTENANCE  = 'maintenance';

    /** CREATE DATABASE / USE / ALTER DATABASE — scoped outside the connection. */
    public const KIND_DATABASE     = 'database';

    /**
     * CREATE / ALTER / DROP SEQUENCE — PostgreSQL's identity plumbing.
     *
     * The sequence object itself does not port: MySQL and SQLite have no such
     * thing. What it *means* is recovered from the accompanying
     * "ALTER TABLE ... SET DEFAULT nextval(...)", which becomes the target's
     * own auto-increment.
     */
    public const KIND_SEQUENCE     = 'sequence';

    /** The `COPY t (cols) FROM stdin;` header that opens a bulk data block. */
    public const KIND_COPY         = 'copy';

    /**
     * One tab-separated row from inside a COPY block.
     *
     * These carry no SQL keywords at all, so they can never classify
     * themselves — the lexer tags them, which is what the $kind constructor
     * argument is for.
     */
    public const KIND_COPY_DATA    = 'copy_data';

    public const KIND_OTHER        = 'other';

    private ?string $kind;

    public function __construct(
        public readonly string $sql,
        public readonly int $ordinal,
        public readonly int $offset,
        ?string $kind = null,
    ) {
        $this->kind = $kind;
    }

    public function __toString(): string
    {
        return $this->sql;
    }

    /**
     * Classify the statement by its leading keywords.
     *
     * @return self::KIND_*
     */
    public function kind(): string
    {
        return $this->kind ??= $this->classify();
    }

    /** The statement with leading comments and whitespace removed. */
    public function body(): string
    {
        $sql = $this->sql;

        // Strip any run of leading comments; a mysqldump statement is often
        // preceded by "-- ..." banner lines or a /*!40000 ... */ guard.
        while (true) {
            $trimmed = ltrim($sql);

            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                $newline = strpos($trimmed, "\n");
                if ($newline === false) {
                    return '';
                }
                $sql = substr($trimmed, $newline + 1);
                continue;
            }

            if (str_starts_with($trimmed, '/*')) {
                $end = strpos($trimmed, '*/');
                if ($end === false) {
                    return '';
                }
                $sql = substr($trimmed, $end + 2);
                continue;
            }

            // psql meta-commands are client instructions, not SQL, and end at
            // the newline rather than the delimiter — so the splitter hands
            // them over glued to whatever statement follows. pg_dump 18 opens
            // every dump with "\restrict <token>" and closes with
            // "\unrestrict <token>"; pg_dumpall adds "\connect".
            //
            // Stripping only the leading run leaves any real SQL behind it
            // intact, so it still classifies and parses normally.
            if (str_starts_with($trimmed, '\\')) {
                $newline = strpos($trimmed, "\n");
                if ($newline === false) {
                    return '';
                }
                $sql = substr($trimmed, $newline + 1);
                continue;
            }

            return $trimmed;
        }
    }

    private function classify(): string
    {
        $body = $this->body();

        if ($body === '') {
            return self::KIND_COMMENT;
        }

        // Normalise whitespace in just the opening keywords.
        $head = strtoupper(preg_replace('/\s+/', ' ', substr($body, 0, 64)) ?? '');

        return match (true) {
            // Checked before CREATE TABLE so "CREATE DATABASE" is not misread.
            (bool) preg_match('/^(USE|CREATE\s+(DATABASE|SCHEMA)|ALTER\s+(DATABASE|SCHEMA)|DROP\s+(DATABASE|SCHEMA))\b/', $head) => self::KIND_DATABASE,

            // Before the TABLE arms so the intent reads in dump order, though
            // the prefixes do not actually overlap.
            (bool) preg_match('/^(CREATE|ALTER|DROP)\s+SEQUENCE\b/', $head)       => self::KIND_SEQUENCE,

            (bool) preg_match('/^CREATE\s+(TEMPORARY\s+)?TABLE\b/', $head)        => self::KIND_CREATE_TABLE,
            (bool) preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\b/', $head)           => self::KIND_CREATE_INDEX,
            (bool) preg_match('/^INSERT\b|^REPLACE\b/', $head)                    => self::KIND_INSERT,
            (bool) preg_match('/^COPY\b.*\bFROM\s+STDIN\b/', $head)               => self::KIND_COPY,
            (bool) preg_match('/^ALTER\s+TABLE\b/', $head)                        => self::KIND_ALTER_TABLE,
            (bool) preg_match('/^DROP\s+TABLE\b/', $head)                         => self::KIND_DROP_TABLE,
            // PRAGMA is SQLite's session-setup statement and leads every
            // `sqlite3 .dump`. It is grouped with SET because both are dropped:
            // no other engine understands it.
            //
            // pg_dump's SELECT pg_catalog.set_config()/setval() calls are the
            // same thing wearing a SELECT: session setup and sequence
            // positioning. Matched on the qualified prefix only — a bare
            // ^SELECT arm would swallow real queries.
            (bool) preg_match('/^(SET|PRAGMA)\b|^SELECT\s+PG_CATALOG\./', $head)  => self::KIND_SET,
            (bool) preg_match('/^(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK)\b/', $head) => self::KIND_TRANSACTION,

            // mysqldump wraps every table in LOCK/UNLOCK. Bare CHECK is left out
            // on purpose — too close to a column-level CHECK constraint to match
            // safely on 64 bytes of head; CHECKSUM is unambiguous.
            (bool) preg_match(
                '/^((LOCK|UNLOCK)\s+TABLES?\b'
                . '|(FLUSH|ANALYZE|OPTIMIZE|REPAIR|CHECKSUM)\s+(NO_WRITE_TO_BINLOG\s+|LOCAL\s+)?TABLES?\b)/',
                $head
            ) => self::KIND_MAINTENANCE,
            default                                                               => self::KIND_OTHER,
        };
    }
}
