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

use Laika\Model\Exceptions\ConverterException;

/**
 * What happened during a conversion.
 *
 * The converter is best-effort by default: it converts what it can and records
 * the rest here, because a dump migration that dies on statement 40,000 of
 * 50,000 is worse than one that flags twelve lossy conversions. Strict mode
 * promotes every warning to an exception instead.
 */
final class Report
{
    /** @var Warning[] */
    private array $warnings = [];

    /** @var array<string,int> Statement kind => count */
    private array $counts = [];

    private int $statements = 0;

    public function __construct(private readonly bool $strict = false) {}

    /**
     * Record a warning.
     *
     * @throws ConverterException In strict mode.
     */
    public function warn(
        int $ordinal,
        string $reason,
        string $level = Warning::LEVEL_LOSSY,
        ?string $excerpt = null,
    ): void {
        $warning = new Warning($ordinal, $reason, $level, $excerpt);

        if ($this->strict) {
            throw new ConverterException((string) $warning);
        }

        $this->warnings[] = $warning;
    }

    /** Count a statement against its kind. */
    public function count(string $kind): void
    {
        $this->statements++;
        $this->counts[$kind] = ($this->counts[$kind] ?? 0) + 1;
    }

    /** @return Warning[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return Warning[] */
    public function warningsOfLevel(string $level): array
    {
        return array_values(array_filter(
            $this->warnings,
            fn(Warning $w): bool => $w->level === $level
        ));
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function warningCount(): int
    {
        return count($this->warnings);
    }

    /** Total statements seen. */
    public function statementCount(): int
    {
        return $this->statements;
    }

    /** @return array<string,int> Statements per kind. */
    public function counts(): array
    {
        return $this->counts;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }

    /** A one-line human summary. */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->counts as $kind => $count) {
            $parts[] = "{$kind}={$count}";
        }

        $breakdown = $parts === [] ? 'none' : implode(', ', $parts);

        return "{$this->statements} statement(s) [{$breakdown}], {$this->warningCount()} warning(s)";
    }
}
