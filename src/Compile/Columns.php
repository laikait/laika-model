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

class Columns extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile CHAR Blueprint
     * @param string $driver Driver Name. Example: 'mysql'
     * @param string $table Table Name
     * @return string
     */
    public function __construct(string $driver, string $table)
    {
        $table = (new Quote($table, $driver))->sql();
        $this->query = match (true) {
            $driver == 'mysql' => "DESCRIBE {$table};",
            $driver == 'pgsql' => "\d {$table};",
            $driver == 'sqlite' => "PRAGMA table_info({$table});",
            $driver == 'sqlsrv' => "EXEC sp_columns {$table};",
            $driver == 'oci' => "DESCRIBE {$table};",
            $driver == 'firebird' => "SHOW TABLE {$table};",
            default => throw new PDOException("Query Builder Detected Invalid Driver: [{$driver}]", 10110)
        };
    }

    public function sql(): ?string
    {
        return $this->query;
    }
}
