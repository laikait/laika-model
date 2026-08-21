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
 * Incremental, quote-aware SQL statement splitter.
 *
 * Splitting a dump on ";" is wrong the moment a string literal contains one,
 * and every real dump contains one. This scanner tracks the lexical context
 * (string literals, quoted identifiers, comments, dollar-quoted bodies) and
 * only breaks on a delimiter found at the top level.
 *
 * It is fed in chunks and keeps its context across chunk boundaries, so a
 * multi-gigabyte file never has to be in memory at once:
 *
 *   $lexer = new Lexer('mysql');
 *   $lexer->feed($chunk);
 *   foreach ($lexer->drain() as $statement) { ... }
 *   // ...more chunks...
 *   foreach ($lexer->finish() as $statement) { ... }   // flush the tail
 */
final class Lexer
{
    public const DEFAULT_DELIMITER = ';';

    /** Not inside anything — a delimiter here ends the statement. */
    private const CTX_NONE = null;

    private const CTX_SINGLE_QUOTE  = 'sq';
    private const CTX_DOUBLE_QUOTE  = 'dq';
    private const CTX_BACKTICK      = 'bt';
    private const CTX_BRACKET       = 'br';
    private const CTX_LINE_COMMENT  = 'lc';
    private const CTX_BLOCK_COMMENT = 'bc';
    private const CTX_DOLLAR        = 'dollar';

    private string $buffer = '';

    /** How far into $buffer we have already scanned. */
    private int $cursor = 0;

    private string $delimiter = self::DEFAULT_DELIMITER;

    /** @var self::CTX_*|null */
    private ?string $context = self::CTX_NONE;

    /** Tag of the dollar-quoted body currently open, e.g. '$$' or '$body$'. */
    private string $dollarTag = '';

    /** Whether backslash escapes are active inside the string currently open. */
    private bool $inStringEscapes = false;

    /** Characters worth stopping on at the top level. */
    private string $interesting;

    /** Set while flushing, so a trailing quote is not held back forever. */
    private bool $finalPass = false;

    /** Raised by the context consumers when they cannot advance any further. */
    private bool $needMore = false;

    /** Byte offset in the overall input of the start of the pending statement. */
    private int $statementOffset = 0;

    /** Bytes of input consumed and discarded from the buffer. */
    private int $consumed = 0;

    private int $ordinal = 0;

    public function __construct(private string $dialect = 'mysql')
    {
        $this->interesting = $this->interestingChars();
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /** Append more input. */
    public function feed(string $chunk): void
    {
        $this->buffer .= $chunk;
    }

    /**
     * Yield every complete statement currently available.
     *
     * @return \Generator<int,Statement>
     */
    public function drain(): \Generator
    {
        while (($statement = $this->next()) !== null) {
            yield $statement;
        }
    }

    /**
     * Flush the tail: emit any trailing statement that has no delimiter.
     *
     * @return \Generator<int,Statement>
     */
    public function finish(): \Generator
    {
        $this->finalPass = true;

        try {
            yield from $this->drain();
        } finally {
            $this->finalPass = false;
        }
    }

    /** The statement terminator currently in force (changed by DELIMITER). */
    public function delimiter(): string
    {
        return $this->delimiter;
    }

    // -----------------------------------------------------------------------
    // Scanning
    // -----------------------------------------------------------------------

    /**
     * Extract the next complete statement, or null when more input is needed.
     *
     * Loops rather than recursing so that empty statements (a stray ";;", or a
     * comment-only line) are skipped without ending the drain early.
     */
    private function next(): ?Statement
    {
        while (true) {
            // A DELIMITER directive is not SQL — it reconfigures the splitter.
            while ($this->consumeDelimiterDirective()) {
                // Dumps often stack these around routine bodies.
            }

            $end = $this->scan();

            if ($end === null) {
                return null;
            }

            $statement = $this->cut($end['end'], $end['delimiter']);

            // Empty (e.g. ";;") — keep going rather than reporting end-of-input.
            if ($statement !== null) {
                return $statement;
            }

            if ($this->buffer === '') {
                return null;
            }
        }
    }

    /**
     * Find the next top-level delimiter.
     *
     * @return ?array{end:int,delimiter:int} Offset of the delimiter and its
     *                                       length, or null if more input is needed.
     */
    private function scan(): ?array
    {
        $length       = strlen($this->buffer);
        $delimiter    = $this->delimiter;
        $delimiterLen = strlen($delimiter);
        $i            = $this->cursor;

        while ($i < $length) {
            // Must be cleared every iteration: a stale "needs more input" flag
            // from a previous call would stall the scan permanently.
            $this->needMore = false;

            if ($this->context !== self::CTX_NONE) {
                $i = $this->consumeContext($i, $length);

                if ($this->needMore) {
                    $this->cursor = $i;
                    return null;
                }

                continue;
            }

            // Skip runs of ordinary text in one jump — scanning byte by byte
            // over a large dump is far too slow.
            $i += strcspn($this->buffer, $this->interesting, $i);
            if ($i >= $length) {
                break;
            }

            // Delimiter at the top level ends the statement.
            if (
                $this->buffer[$i] === $delimiter[0]
                && substr_compare($this->buffer, $delimiter, $i, $delimiterLen) === 0
            ) {
                return ['end' => $i, 'delimiter' => $delimiterLen];
            }

            $opened = $this->openContext($i, $length);

            if ($this->needMore) {
                $this->cursor = $i;
                return null;
            }

            // A '-', '/' or '$' that opens nothing is ordinary text; step over
            // it so the next strcspn does not stop on it again.
            $i = $opened ?? $i + 1;
        }

        $this->cursor = $i;

        if ($this->finalPass && $this->context === self::CTX_NONE && trim($this->buffer) !== '') {
            return ['end' => $length, 'delimiter' => 0];
        }

        return null;
    }

    /**
     * Try to open a lexical context at $i.
     *
     * @return ?int New scan position if a context was opened, null otherwise.
     */
    private function openContext(int $i, int $length): ?int
    {
        $char = $this->buffer[$i];
        $next = $i + 1 < $length ? $this->buffer[$i + 1] : '';

        switch ($char) {
            case "'":
                $this->context = self::CTX_SINGLE_QUOTE;
                // MySQL always honours backslash escapes; PostgreSQL only does
                // inside an E'...' string, and SQLite never does.
                $this->inStringEscapes = $this->dialect === 'mysql'
                    || ($this->dialect === 'pgsql' && $i > 0 && ($this->buffer[$i - 1] === 'E' || $this->buffer[$i - 1] === 'e'));
                return $i + 1;

            case '"':
                $this->context         = self::CTX_DOUBLE_QUOTE;
                $this->inStringEscapes = $this->dialect === 'mysql';
                return $i + 1;

            case '`':
                $this->context = self::CTX_BACKTICK;
                return $i + 1;

            case '[':
                $this->context = self::CTX_BRACKET;
                return $i + 1;

            case '#':
                $this->context = self::CTX_LINE_COMMENT;
                return $i + 1;

            case '-':
                // The second '-' has not arrived yet — stepping over this one
                // would lose the chance to recognise the comment at all.
                if ($next === '' && !$this->finalPass) {
                    $this->needMore = true;
                    return null;
                }

                // "--" is a comment only when followed by whitespace or EOL, so
                // the "5--3" arithmetic case is not swallowed.
                if ($next === '-') {
                    if ($i + 2 >= $length) {
                        // Cannot tell yet whether whitespace follows.
                        if (!$this->finalPass) {
                            $this->needMore = true;
                            return null;
                        }
                        $this->context = self::CTX_LINE_COMMENT;
                        return $length;
                    }
                    if ($this->isSpace($this->buffer[$i + 2])) {
                        $this->context = self::CTX_LINE_COMMENT;
                        return $i + 2;
                    }
                }
                return null;

            case '/':
                if ($next === '' && !$this->finalPass) {
                    $this->needMore = true;
                    return null;
                }
                if ($next === '*') {
                    // Includes MySQL conditional comments (/*!40101 ... */).
                    // Their body is executable, but for *splitting* purposes
                    // they behave like a comment: the delimiter follows "*/".
                    $this->context = self::CTX_BLOCK_COMMENT;
                    return $i + 2;
                }
                return null;

            case '$':
                if (preg_match('/\G(\$\$|\$[A-Za-z_\x80-\xff][A-Za-z_0-9\x80-\xff]*\$)/', $this->buffer, $m, 0, $i)) {
                    $this->context   = self::CTX_DOLLAR;
                    $this->dollarTag = $m[0];
                    return $i + strlen($m[0]);
                }
                return null;
        }

        return null;
    }

    /**
     * Advance through the currently open context, closing it when its
     * terminator is found. Raises $needMore when the buffer runs out.
     */
    private function consumeContext(int $i, int $length): int
    {
        switch ($this->context) {
            case self::CTX_SINGLE_QUOTE:
                return $this->consumeQuoted($i, $length, "'", $this->inStringEscapes);

            case self::CTX_DOUBLE_QUOTE:
                return $this->consumeQuoted($i, $length, '"', $this->inStringEscapes);

            case self::CTX_BACKTICK:
                return $this->consumeQuoted($i, $length, '`', false);

            case self::CTX_BRACKET:
                return $this->consumeQuoted($i, $length, ']', false);

            case self::CTX_LINE_COMMENT:
                $newline = strpos($this->buffer, "\n", $i);
                if ($newline === false) {
                    // An unterminated line comment at EOF ends the statement.
                    $this->needMore = !$this->finalPass;
                    if ($this->finalPass) {
                        $this->context = self::CTX_NONE;
                    }
                    return $length;
                }
                $this->context = self::CTX_NONE;
                return $newline + 1;

            case self::CTX_BLOCK_COMMENT:
                return $this->consumeUntil($i, $length, '*/');

            case self::CTX_DOLLAR:
                $position = $this->consumeUntil($i, $length, $this->dollarTag);
                if (!$this->needMore) {
                    $this->dollarTag = '';
                }
                return $position;
        }

        $this->needMore = true;
        return $length;
    }

    /** Scan forward to $terminator, keeping enough tail for a split match. */
    private function consumeUntil(int $i, int $length, string $terminator): int
    {
        $end = strpos($this->buffer, $terminator, $i);

        if ($end !== false) {
            $this->context = self::CTX_NONE;
            return $end + strlen($terminator);
        }

        if ($this->finalPass) {
            $this->context = self::CTX_NONE;
            return $length;
        }

        // The terminator may straddle the chunk boundary — keep its length
        // minus one byte so the next feed can complete the match.
        $this->needMore = true;

        return max($i, $length - strlen($terminator) + 1);
    }

    /**
     * Consume a quoted region up to $close.
     *
     * Handles both escape conventions: doubling the quote ('' or ]]) and, where
     * the dialect allows it, a backslash escape.
     */
    private function consumeQuoted(int $i, int $length, string $close, bool $backslashEscapes): int
    {
        $stops = $backslashEscapes ? $close . '\\' : $close;

        while ($i < $length) {
            $i += strcspn($this->buffer, $stops, $i);
            if ($i >= $length) {
                break;
            }

            if ($this->buffer[$i] === '\\') {
                // Escaped character — skip it. If the escaped byte has not
                // arrived yet, resume once it does.
                if ($i + 1 >= $length) {
                    if ($this->finalPass) {
                        break;
                    }
                    $this->needMore = true;
                    return $i;
                }
                $i += 2;
                continue;
            }

            // A doubled terminator ('' or ]]) is a literal, not the end.
            if ($i + 1 < $length && $this->buffer[$i + 1] === $close) {
                $i += 2;
                continue;
            }

            // A terminator at the very end of the buffer is ambiguous until we
            // can see whether the next byte doubles it.
            if ($i + 1 >= $length && !$this->finalPass) {
                $this->needMore = true;
                return $i;
            }

            $this->context = self::CTX_NONE;
            return $i + 1;
        }

        if ($this->finalPass) {
            $this->context = self::CTX_NONE;
            return $length;
        }

        $this->needMore = true;
        return $length;
    }

    /**
     * Consume a leading `DELIMITER x` line, if present.
     *
     * phpMyAdmin and mysqldump emit these around routine bodies so that the
     * semicolons inside do not terminate the CREATE statement.
     */
    private function consumeDelimiterDirective(): bool
    {
        if ($this->context !== self::CTX_NONE || $this->cursor !== 0) {
            return false;
        }

        if (!preg_match('/^\s*DELIMITER[ \t]+(\S+)[ \t]*(\r?\n|$)/i', $this->buffer, $m)) {
            return false;
        }

        // Without a newline the token may still be growing.
        if ($m[2] === '' && !$this->finalPass) {
            return false;
        }

        $this->delimiter   = $m[1];
        $this->interesting = $this->interestingChars();

        $this->discard(strlen($m[0]));

        return true;
    }

    // -----------------------------------------------------------------------
    // Buffer management
    // -----------------------------------------------------------------------

    /**
     * Emit the statement ending at $end and drop it from the buffer.
     *
     * @return ?Statement Null when the statement was blank.
     */
    private function cut(int $end, int $delimiterLen): ?Statement
    {
        $sql    = substr($this->buffer, 0, $end);
        $offset = $this->statementOffset;

        $this->discard($end + $delimiterLen);

        $trimmed = trim($sql);

        return $trimmed === '' ? null : new Statement($trimmed, ++$this->ordinal, $offset);
    }

    /** Drop $bytes from the front of the buffer and reset the scan cursor. */
    private function discard(int $bytes): void
    {
        $this->buffer           = substr($this->buffer, $bytes);
        $this->consumed        += $bytes;
        $this->statementOffset  = $this->consumed;
        $this->cursor           = 0;
    }

    /**
     * Which characters can open a lexical context in this dialect.
     *
     * Kept dialect-specific on purpose: treating '[' as an identifier quote
     * would corrupt any dialect that uses it arithmetically, and '$' only opens
     * a dollar-quoted body in PostgreSQL.
     */
    private function interestingChars(): string
    {
        $chars = "'\"-/" . $this->delimiter[0];

        $chars .= match ($this->dialect) {
            'mysql'  => '`#',
            'sqlsrv' => '[',
            'pgsql'  => '$',
            default  => '',
        };

        return $chars;
    }

    private function isSpace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n"
            || $char === "\r" || $char === "\0" || $char === "\x0B";
    }
}
