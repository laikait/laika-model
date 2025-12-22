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

class Drop extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile DROP Blueprint
     * @param string $table Table Name. Example: 'users'
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(string $table, string $driver)
    {
        $this->query = match ($driver) {
            'mysql' => "DROP TABLE IF EXISTS `{$table}`;",
            'pgsql' => "DROP TABLE IF EXISTS {$table};",
            'sqlite' => "DROP TABLE IF EXISTS {$table};",
            'sqlsrv' => "IF OBJECT_ID('{$table}', 'U') IS NOT NULL DROP TABLE {$table};",
            'oci' => "DROP TABLE {$table};",
            'firebird' => "DROP TABLE {$table};",
            default => new PDOException("Query Builder Detected Invalid Driver: [{$driver}]", 10110)
        };
    }

    public function sql(): ?string
    {
        return $this->query;
    }
}
