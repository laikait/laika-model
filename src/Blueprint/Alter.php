<?php

/**
 * Laika Database Model
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika Laika Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Model\Blueprint;

use Laika\Model\Abstracts\ColumnBlueprint;

class Alter extends ColumnBlueprint
{
    ######################################################################
    /*--------------------------- PUBLIC API ---------------------------*/
    ######################################################################

    public function __construct(string $column, string $driver, string $table)
    {
        parent::__construct(trim($column), $driver, $table);
    }

    public function __toString()
    {
        throw new \Exception('Not implemented');
    }
}