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

class Rename extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile YEAR Blueprint
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(string $driver, string $table, string $name)
    {
        // Make Quoted Strings
        $table = call_user_func([new Quote($table, $driver), 'sql']);
        $name = call_user_func([new Quote($name, $driver), 'sql']);

        $this->query = match (true) {
            $driver == 'mysql' => "RENAME TABLE {$table} TO {$name};",
            $driver == 'pgsql' => "ALTER TABLE {$table} RENAME TO {$name};",
            $driver == 'sqlite' => "ALTER TABLE {$table} RENAME TO {$name};",
            $driver == 'sqlsrv' => "EXEC sp_rename {$table}, {$name};",
            $driver == 'oci' => "RENAME {$table} TO {$name};",
            $driver == 'firebird' => throw new PDOException("Unsupported Query for Driver: [{$driver}]", 10110),
            default => throw new PDOException("Query Builder Detected Invalid Driver: [{$driver}]", 10110)
        };
    }

    public function sql(): ?string
    {
        return $this->query;
    }
}
