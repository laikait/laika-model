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

class Tinyint extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile TINYINT Blueprint
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(string $driver)
    {
        $this->query = match (true) {
            $driver == 'mysql' => 'TINYINT',
            $driver == 'pgsql' => 'SMALLINT',
            $driver == 'sqlite' => 'INTEGER',
            $driver == 'sqlsrv' => 'SMALLINT',
            $driver == 'oci' => 'NUMBER',
            $driver == 'firebird' => 'SMALLINT',
            default => throw new PDOException("Query Builder Detected Invalid Driver: [{$driver}]", 10110)
        };
    }

    public function sql(): ?string
    {
        return $this->query;
    }
}
