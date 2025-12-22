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

class Unique extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile UNIQUE Query Blueprint
     * @param string $column Column Name. Example: 'id'
     * @param string $table Table Name. Example: 'users'
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(string $column, string $table, string $driver)
    {
        switch ($driver) {
            case 'mysql':
                $this->query = "CREATE UNIQUE INDEX IF NOT EXISTS `uindex_{$column}` ON `{$table}` (`{$column}`);";
                break;

            case 'pgsql':
                $this->query = "CREATE UNIQUE INDEX IF NOT EXISTS uindex_{$column} ON {$table} ({$column});";
                break;

            case 'sqlite':
                $this->query = "CREATE UNIQUE INDEX IF NOT EXISTS uindex_{$column} ON {$table} ({$column});";
                break;

            case 'sqlsrv':
                $this->query = "CREATE UNIQUE INDEX uindex_{$column} ON {$table} ({$column});";
                break;

            case 'oci':
                $this->query = "CREATE UNIQUE INDEX uindex_{$column} ON {$table} ({$column});";
                break;

            default:
                throw new PDOException("Query Builder Detected Invalid Driver: [{$driver}]", 10110);
                break;
        }
    }

    public function sql(): ?string
    {
        return $this->query;
    }
}
