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
 * One thing the conversion could not do faithfully.
 */
final class Warning
{
    /** Converted, but something was approximated or dropped. */
    public const LEVEL_LOSSY = 'lossy';

    /** Not understood — passed through unchanged. */
    public const LEVEL_PASSTHROUGH = 'passthrough';

    /** Understood and deliberately skipped (out of scope). */
    public const LEVEL_SKIPPED = 'skipped';

    public function __construct(
        public readonly int $ordinal,
        public readonly string $reason,
        public readonly string $level = self::LEVEL_LOSSY,
        public readonly ?string $excerpt = null,
    ) {}

    public function __toString(): string
    {
        $text = "#{$this->ordinal} [{$this->level}] {$this->reason}";

        return $this->excerpt === null ? $text : "{$text} — {$this->excerpt}";
    }
}
