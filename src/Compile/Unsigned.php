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

namespace Laika\Model\Compile;

use PDOException;
use Laika\Model\Abstracts\BuilderHelper;

class Unsigned extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile UNSIGNED Blueprint
     * @param string $column Column Name. Example: 'id'
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(string $column, string $driver)
    {
        $this->query = match (true) {
            $driver == 'mysql' => "UNSIGNED",
            $driver == 'pgsql' => "CHECK ({$column} >= 0)",
            $driver == 'sqlite' => "CHECK ({$column} >= 0)",
            $driver == 'sqlsrv' => "CHECK ({$column} >= 0)",
            $driver == 'oci' => "CHECK ({$column} >= 0)",
            $driver == 'firebird' => "CHECK ({$column} >= 0)",
            default => throw new PDOException("Query Builder Detected Invalid Driver: [{$driver}]", 10110)
        };
    }

    public function sql(): ?string
    {
        return $this->query;
    }
}
