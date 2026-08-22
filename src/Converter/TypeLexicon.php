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
 * Native SQL type -> canonical Blueprint type.
 *
 * This is the inverse of the type tables in the Grammars: the grammars expand a
 * canonical type into native SQL, this collapses native SQL back to canonical
 * so the target grammar can re-expand it. The two must stay in step, which
 * TypeLexiconTest enforces by round-tripping every canonical type.
 *
 * Unknown types return null; the caller carries them through verbatim and
 * records a warning rather than guessing.
 */
final class TypeLexicon
{
    /**
     * Types shared across dialects. Keys are lowercase and un-parameterised —
     * "varchar(190)" is reduced to "varchar" before lookup.
     *
     * @var array<string,string>
     */
    private const COMMON = [
        // Integers
        'tinyint'    => 'tinyInteger',
        'smallint'   => 'smallInteger',
        'mediumint'  => 'integer',
        'int'        => 'integer',
        'integer'    => 'integer',
        'bigint'     => 'bigInteger',
        'int2'       => 'smallInteger',
        'int4'       => 'integer',
        'int8'       => 'bigInteger',

        // Reals
        'float'            => 'float',
        'real'             => 'float',
        'float4'           => 'float',
        'double'           => 'double',
        'double precision' => 'double',
        'float8'           => 'double',
        'decimal'          => 'decimal',
        'numeric'          => 'decimal',
        'dec'              => 'decimal',
        'money'            => 'decimal',

        // Booleans
        'boolean' => 'boolean',
        'bool'    => 'boolean',
        'bit'     => 'boolean',

        // Strings
        'varchar'           => 'string',
        'character varying' => 'string',
        'nvarchar'          => 'string',
        'varchar2'          => 'string',
        'char'              => 'char',
        'character'         => 'char',
        'nchar'             => 'char',
        'bpchar'            => 'char',

        // Text
        'text'       => 'text',
        'ntext'      => 'text',
        'tinytext'   => 'text',
        'mediumtext' => 'mediumText',
        'longtext'   => 'longText',
        'clob'       => 'longText',

        // Dates and times
        'date'                        => 'date',
        'time'                        => 'time',
        'time without time zone'      => 'time',
        'datetime'                    => 'dateTime',
        'datetime2'                   => 'dateTime',
        'smalldatetime'               => 'dateTime',
        'timestamp'                   => 'timestamp',
        'timestamp without time zone' => 'timestamp',
        'timestamp with time zone'    => 'timestamp',
        'timestamptz'                 => 'timestamp',

        // Structured
        'json'  => 'json',
        'jsonb' => 'json',

        // Binary
        'binary'     => 'binary',
        'varbinary'  => 'binary',
        'bytea'      => 'blob',
        'tinyblob'   => 'tinyBlob',
        'blob'       => 'blob',
        'mediumblob' => 'mediumBlob',
        'longblob'   => 'longBlob',
        'image'      => 'blob',

        // Identifiers
        'uuid'             => 'uid',
        'uniqueidentifier' => 'uid',

        // Sets
        'enum' => 'enum',
        'set'  => 'set',
    ];

    /**
     * Dialect-specific readings that differ from COMMON.
     *
     * @var array<string,array<string,string>>
     */
    private const PER_DIALECT = [
        'pgsql' => [
            // PostgreSQL's auto-increment pseudo-types.
            'serial'      => 'id',
            'serial4'     => 'id',
            'bigserial'   => 'bigId',
            'serial8'     => 'bigId',
            'smallserial' => 'smallInteger',
            // "bit" in PostgreSQL is a bit-string, not a boolean.
            'bit'         => 'string',
        ],
        'sqlsrv' => [
            // A T-SQL TIMESTAMP is a rowversion counter, not a point in time.
            'timestamp'  => 'binary',
            'rowversion' => 'binary',
        ],
        'sqlite' => [
            // SQLite's storage classes.
            'integer' => 'integer',
            'real'    => 'double',
            'numeric' => 'decimal',
        ],
    ];

    public function __construct(private readonly string $dialect = 'mysql') {}

    /**
     * Reduce a native type declaration to a canonical Blueprint type.
     *
     * Accepts the full declaration including parameters, e.g. "VARCHAR(190)",
     * "DECIMAL(8, 2)", "ENUM('a','b')", "INT UNSIGNED".
     *
     * @return ?string Canonical type name, or null if unrecognised.
     */
    public function toCanonical(string $nativeType): ?string
    {
        $base = $this->baseName($nativeType);

        if ($base === '') {
            return null;
        }

        return self::PER_DIALECT[$this->dialect][$base]
            ?? self::COMMON[$base]
            ?? null;
    }

    /**
     * Strip parameters and modifiers down to the bare type name.
     *
     * "VARCHAR(190)"        -> "varchar"
     * "INT(10) UNSIGNED"    -> "int"
     * "DOUBLE PRECISION"    -> "double precision"
     * "TIMESTAMP(6) WITH TIME ZONE" -> "timestamp with time zone"
     */
    public function baseName(string $nativeType): string
    {
        $type = strtolower(trim($nativeType));

        // Drop a parameter list wherever it appears: "timestamp(6) with time
        // zone" must keep the trailing words.
        $type = preg_replace('/\s*\([^()]*\)/', '', $type) ?? $type;

        // Drop modifiers that are not part of the type's identity.
        $type = preg_replace('/\b(unsigned|signed|zerofill|varying)\b/', ' ', $type) ?? $type;

        // "character varying" is a single type name, so restore it after the
        // "varying" strip above removed the second word.
        $type = trim(preg_replace('/\s+/', ' ', $type) ?? $type);

        if ($type === 'character') {
            // Ambiguous only if "varying" was stripped; the caller passed
            // "character varying" if the original contained it.
            $type = str_contains(strtolower($nativeType), 'varying') ? 'character varying' : 'character';
        }

        return $type;
    }

    /**
     * Extract the length from a single-parameter type, e.g. VARCHAR(190).
     */
    public function length(string $nativeType): ?int
    {
        if (preg_match('/\(\s*(\d+)\s*\)/', $nativeType, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Extract precision and scale from DECIMAL(p, s) / NUMERIC(p, s).
     *
     * @return ?array{precision:int,scale:int}
     */
    public function precisionAndScale(string $nativeType): ?array
    {
        if (preg_match('/\(\s*(\d+)\s*,\s*(\d+)\s*\)/', $nativeType, $m)) {
            return ['precision' => (int) $m[1], 'scale' => (int) $m[2]];
        }

        return null;
    }

    /**
     * Extract the member list from ENUM(...) / SET(...).
     *
     * @return string[] Unquoted values; empty when there are none.
     */
    public function members(string $nativeType): array
    {
        if (!preg_match('/\((.*)\)\s*$/s', $nativeType, $m)) {
            return [];
        }

        $values = [];

        // Split on commas outside string literals, honouring '' doubling.
        if (preg_match_all("/'((?:[^']|'')*)'/", $m[1], $matches)) {
            foreach ($matches[1] as $value) {
                $values[] = str_replace("''", "'", $value);
            }
        }

        return $values;
    }

    /** True when the declaration carries an UNSIGNED modifier. */
    public function isUnsigned(string $nativeType): bool
    {
        return (bool) preg_match('/\bunsigned\b/i', $nativeType);
    }

    /** Every canonical type this lexicon can produce. */
    public static function canonicalTypes(): array
    {
        $types = array_values(self::COMMON);

        foreach (self::PER_DIALECT as $map) {
            $types = array_merge($types, array_values($map));
        }

        return array_values(array_unique($types));
    }
}
