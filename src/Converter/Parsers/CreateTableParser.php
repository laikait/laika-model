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

use Laika\Model\Converter\BlueprintBuilder;
use Laika\Model\Converter\Report;
use Laika\Model\Converter\SqlScanner;
use Laika\Model\Converter\Statement;
use Laika\Model\Converter\TypeLexicon;
use Laika\Model\Converter\Warning;
use Laika\Model\Schema\Blueprint;

/**
 * CREATE TABLE -> Blueprint.
 *
 * Deliberately not a full SQL grammar: it reads what dumps actually contain —
 * a column list with inline constraints and a tail of table options — and
 * reports anything it does not recognise rather than guessing.
 */
final class CreateTableParser
{
    /**
     * Words that continue a multi-word type rather than starting a modifier.
     * "DOUBLE PRECISION", "CHARACTER VARYING", "TIMESTAMP WITH TIME ZONE".
     */
    private const TYPE_CONTINUATIONS = [
        'precision', 'varying', 'with', 'without', 'time', 'zone', 'local',
    ];

    public function __construct(
        private readonly TypeLexicon $lexicon,
        private readonly BlueprintBuilder $builder,
        private readonly Report $report,
    ) {}

    /**
     * @return ?Blueprint Null when the statement is not a CREATE TABLE we can read.
     */
    public function parse(Statement $statement): ?Blueprint
    {
        $sql = $statement->body();

        if (!preg_match('/^CREATE\s+(TEMPORARY\s+)?TABLE\s+/i', $sql, $head)) {
            return null;
        }

        $pos          = strlen($head[0]);
        $ifNotExists  = false;

        if (preg_match('/\GIF\s+NOT\s+EXISTS\s+/i', $sql, $m, 0, $pos)) {
            $ifNotExists = true;
            $pos        += strlen($m[0]);
        }

        $table = SqlScanner::readIdentifier($sql, $pos);

        if ($table === null || $table === '') {
            $this->report->warn(
                $statement->ordinal,
                'CREATE TABLE with no readable table name; passed through unchanged.',
                Warning::LEVEL_PASSTHROUGH,
                $this->excerpt($sql)
            );

            return null;
        }

        $open = strpos($sql, '(', $pos);

        if ($open === false) {
            // CREATE TABLE ... LIKE / AS SELECT — no column list to read.
            $this->report->warn(
                $statement->ordinal,
                "Table [{$table}]: CREATE TABLE without a column list is not supported; passed through unchanged.",
                Warning::LEVEL_PASSTHROUGH,
                $this->excerpt($sql)
            );

            return null;
        }

        $close = SqlScanner::matchingParen($sql, $open);

        if ($close === null) {
            $this->report->warn(
                $statement->ordinal,
                "Table [{$table}]: unbalanced parentheses in the column list; passed through unchanged.",
                Warning::LEVEL_PASSTHROUGH,
                $this->excerpt($sql)
            );

            return null;
        }

        $options = $this->parseTableOptions(substr($sql, $close + 1));

        if ($ifNotExists) {
            $options['ifNotExists'] = true;
        }

        $blueprint = new Blueprint($table, $options);
        $body      = substr($sql, $open + 1, $close - $open - 1);

        foreach (SqlScanner::splitTopLevel($body) as $part) {
            $this->parseBodyPart($blueprint, $part, $statement);
        }

        return $blueprint;
    }

    /** Route one comma-separated element to a constraint or a column. */
    private function parseBodyPart(Blueprint $blueprint, string $part, Statement $statement): void
    {
        if ($this->parseConstraint($blueprint, $part, $statement)) {
            return;
        }

        $column = $this->parseColumn($part, $statement);

        if ($column === null) {
            return;
        }

        $this->builder->addColumn($blueprint, $column, $statement->ordinal);

        // A column-level PRIMARY KEY, unless the column is already an
        // auto-increment id (which every grammar makes the primary key itself).
        if (!empty($column['primary']) && empty($column['auto_increment'])) {
            $blueprint->primary($column['name']);
        }

        if (!empty($column['unique'])) {
            $blueprint->unique($column['name']);
        }
    }

    /**
     * Handle a table-level constraint.
     *
     * @return bool True when $part was a constraint (and has been applied).
     */
    private function parseConstraint(Blueprint $blueprint, string $part, Statement $statement): bool
    {
        $name = null;

        // An optional CONSTRAINT <name> prefix applies to whatever follows.
        if (preg_match('/^CONSTRAINT\s+/i', $part, $m)) {
            $pos  = strlen($m[0]);
            $name = SqlScanner::readIdentifier($part, $pos);
            $part = trim(substr($part, $pos));
        }

        if (preg_match('/^PRIMARY\s+KEY\s*\((.*)\)\s*$/is', $part, $m)) {
            $blueprint->primary($this->columnList($m[1]));
            return true;
        }

        if (preg_match('/^UNIQUE(?:\s+(?:KEY|INDEX))?\s*(.*)$/is', $part, $m)) {
            [$indexName, $columns] = $this->namedColumnList($m[1], $name);

            if ($columns !== []) {
                $blueprint->unique($columns, $indexName);
                return true;
            }
        }

        if (preg_match('/^(?:KEY|INDEX)\s+(.*)$/is', $part, $m)) {
            [$indexName, $columns] = $this->namedColumnList($m[1], $name);

            if ($columns !== []) {
                $blueprint->index($columns, $indexName);
                return true;
            }
        }

        if (preg_match('/^FOREIGN\s+KEY\s*\((.*?)\)\s*REFERENCES\s+(.*)$/is', $part, $m)) {
            $this->applyForeignKey($blueprint, $m[1], $m[2], $name, $statement);
            return true;
        }

        if (preg_match('/^(CHECK|FULLTEXT|SPATIAL)\b/i', $part, $m)) {
            $this->report->warn(
                $statement->ordinal,
                'Table [' . $blueprint->getTable() . "]: {$m[1]} constraint dropped — no portable equivalent.",
                Warning::LEVEL_LOSSY,
                $this->excerpt($part)
            );

            return true;
        }

        return false;
    }

    /** FOREIGN KEY (a) REFERENCES t (b) ON DELETE ... ON UPDATE ... */
    private function applyForeignKey(
        Blueprint $blueprint,
        string $localColumns,
        string $reference,
        ?string $name,
        Statement $statement,
    ): void {
        $columns = $this->columnList($localColumns);

        if (count($columns) !== 1) {
            $this->report->warn(
                $statement->ordinal,
                'Table [' . $blueprint->getTable() . ']: composite foreign key on ('
                . implode(', ', $columns) . ') dropped — only single-column keys are representable.',
                Warning::LEVEL_LOSSY
            );

            return;
        }

        $pos            = 0;
        $referenceTable = SqlScanner::readIdentifier($reference, $pos);
        $rest           = substr($reference, $pos);

        if ($referenceTable === null || !preg_match('/^\s*\((.*?)\)/s', $rest, $m)) {
            $this->report->warn(
                $statement->ordinal,
                'Table [' . $blueprint->getTable() . ']: unreadable REFERENCES clause; foreign key dropped.',
                Warning::LEVEL_LOSSY,
                $this->excerpt($reference)
            );

            return;
        }

        $referenceColumns = $this->columnList($m[1]);
        $foreign          = $blueprint->foreign($columns[0], $name);
        $foreign->reference($referenceColumns[0] ?? 'id')->on($referenceTable);

        if (preg_match('/ON\s+DELETE\s+(CASCADE|SET\s+NULL|SET\s+DEFAULT|RESTRICT|NO\s+ACTION)/i', $rest, $m)) {
            $foreign->onDelete(preg_replace('/\s+/', ' ', $m[1]));
        }

        if (preg_match('/ON\s+UPDATE\s+(CASCADE|SET\s+NULL|SET\s+DEFAULT|RESTRICT|NO\s+ACTION)/i', $rest, $m)) {
            $foreign->onUpdate(preg_replace('/\s+/', ' ', $m[1]));
        }
    }

    /**
     * `[name] (col, col)` — the name is optional and may already have come from
     * a CONSTRAINT prefix.
     *
     * @return array{0:?string,1:string[]}
     */
    private function namedColumnList(string $rest, ?string $name): array
    {
        $rest = trim($rest);

        if (!str_starts_with($rest, '(')) {
            $pos  = 0;
            $read = SqlScanner::readIdentifier($rest, $pos);

            if ($read !== null) {
                $name = $name ?? $read;
                $rest = trim(substr($rest, $pos));
            }
        }

        if (!preg_match('/^\((.*)\)\s*$/s', $rest, $m)) {
            return [$name, []];
        }

        return [$name, $this->columnList($m[1])];
    }

    /**
     * Split and unquote a parenthesised column list, dropping index prefixes
     * and sort direction: `` `email`(10) DESC `` -> `email`.
     *
     * @return string[]
     */
    private function columnList(string $list): array
    {
        $columns = [];

        foreach (SqlScanner::splitTopLevel($list) as $entry) {
            $pos  = 0;
            $name = SqlScanner::readIdentifier($entry, $pos);

            if ($name !== null && $name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    /**
     * Parse one column definition.
     *
     * @return ?array<string,mixed>
     */
    private function parseColumn(string $part, Statement $statement): ?array
    {
        $pos  = 0;
        $name = SqlScanner::readIdentifier($part, $pos);

        if ($name === null || $name === '') {
            $this->report->warn(
                $statement->ordinal,
                'Unreadable column definition; skipped.',
                Warning::LEVEL_PASSTHROUGH,
                $this->excerpt($part)
            );

            return null;
        }

        $type = $this->readType($part, $pos);

        if ($type === '') {
            $this->report->warn(
                $statement->ordinal,
                "Column [{$name}] has no readable type; skipped.",
                Warning::LEVEL_PASSTHROUGH,
                $this->excerpt($part)
            );

            return null;
        }

        $modifiers = substr($part, $pos);

        return [
            'name'           => $name,
            'type'           => $type,
            'unsigned'       => $this->lexicon->isUnsigned($type) || (bool) preg_match('/\bUNSIGNED\b/i', $modifiers),
            'nullable'       => $this->readNullability($modifiers),
            'default'        => $this->readDefault($modifiers),
            'auto_increment' => (bool) preg_match('/\b(AUTO_INCREMENT|AUTOINCREMENT|IDENTITY\b|GENERATED\s+(ALWAYS|BY\s+DEFAULT)\s+AS\s+IDENTITY)/i', $modifiers),
            'comment'        => $this->readComment($modifiers),
            'primary'        => (bool) preg_match('/\bPRIMARY\s+KEY\b/i', $modifiers),
            'unique'         => (bool) preg_match('/\bUNIQUE\b/i', $modifiers),
        ];
    }

    /**
     * Read the type declaration, including parameters and continuation words.
     */
    private function readType(string $part, int &$pos): string
    {
        $length = strlen($part);

        while ($pos < $length && ($part[$pos] === ' ' || $part[$pos] === "\t" || $part[$pos] === "\n" || $part[$pos] === "\r")) {
            $pos++;
        }

        $start = $pos;

        if (!preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $part, $m, 0, $pos)) {
            return '';
        }

        $pos += strlen($m[0]);

        // Parameter list, e.g. VARCHAR(190) or ENUM('a','b').
        if ($pos < $length && $part[$pos] === '(') {
            $close = SqlScanner::matchingParen($part, $pos);
            $pos   = $close === null ? $length : $close + 1;
        }

        // Multi-word types.
        while (preg_match('/\G\s+([A-Za-z]+)/', $part, $m, 0, $pos)) {
            if (!in_array(strtolower($m[1]), self::TYPE_CONTINUATIONS, true)) {
                break;
            }

            $pos += strlen($m[0]);

            if ($pos < $length && $part[$pos] === '(') {
                $close = SqlScanner::matchingParen($part, $pos);
                $pos   = $close === null ? $length : $close + 1;
            }
        }

        return trim(substr($part, $start, $pos - $start));
    }

    /** NULL / NOT NULL. Columns are nullable by default in every dialect. */
    private function readNullability(string $modifiers): bool
    {
        if (preg_match('/\bNOT\s+NULL\b/i', $modifiers)) {
            return false;
        }

        // An explicit NULL, or nothing at all — both mean nullable. A PRIMARY
        // KEY column is implicitly NOT NULL.
        return !preg_match('/\bPRIMARY\s+KEY\b/i', $modifiers);
    }

    /** The raw DEFAULT expression, still in source form. */
    private function readDefault(string $modifiers): ?string
    {
        if (!preg_match('/\bDEFAULT\s+/i', $modifiers, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $pos    = $m[0][1] + strlen($m[0][0]);
        $length = strlen($modifiers);

        // A quoted literal.
        if ($pos < $length && $modifiers[$pos] === "'") {
            $end = SqlScanner::skipQuoted($modifiers, $pos);
            return substr($modifiers, $pos, $end - $pos + 1);
        }

        // A function call or keyword, possibly with parentheses.
        if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $modifiers, $m2, 0, $pos)) {
            $end = $pos + strlen($m2[0]);

            if ($end < $length && $modifiers[$end] === '(') {
                $close = SqlScanner::matchingParen($modifiers, $end);
                $end   = $close === null ? $length : $close + 1;
            }

            return substr($modifiers, $pos, $end - $pos);
        }

        // A number, possibly signed.
        if (preg_match('/\G[-+]?[0-9.]+/', $modifiers, $m3, 0, $pos)) {
            return $m3[0];
        }

        return null;
    }

    private function readComment(string $modifiers): ?string
    {
        if (!preg_match("/\bCOMMENT\s+('(?:[^']|'')*')/i", $modifiers, $m)) {
            return null;
        }

        return SqlScanner::unquoteString($m[1]);
    }

    /**
     * Read the tail after the closing paren: ENGINE=..., CHARSET=..., etc.
     *
     * @return array<string,mixed>
     */
    private function parseTableOptions(string $tail): array
    {
        $options = [];

        if (preg_match('/\bENGINE\s*=\s*(\w+)/i', $tail, $m)) {
            $options['engine'] = $m[1];
        }

        if (preg_match('/\b(?:DEFAULT\s+)?(?:CHARSET|CHARACTER\s+SET)\s*=?\s*(\w+)/i', $tail, $m)) {
            $options['charset'] = $m[1];
        }

        if (preg_match('/\bCOLLATE\s*=?\s*(\w+)/i', $tail, $m)) {
            $options['collation'] = $m[1];
        }

        return $options;
    }

    private function excerpt(string $sql): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        return strlen($flat) > 120 ? substr($flat, 0, 117) . '...' : $flat;
    }
}
