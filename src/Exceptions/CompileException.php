<?php

/**
 * Laika Database Model
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Model\Exceptions;

use Throwable;

class CompileException extends \Exception
{
    public function __construct(string $message, int $code = 20100, Throwable|null $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}