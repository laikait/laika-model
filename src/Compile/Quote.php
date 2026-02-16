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

class Quote extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile Quote String Query Blueprint
     * @param string $string Example: true or false
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(string $string, string $driver)
    {
        switch ($driver) {
            case 'mysql':
                $this->query = "`{$string}`";
                break;

            case 'pgsql':
                $this->query = "\"{$string}\"";
                break;

            case 'sqlite':
                $this->query = "\"{$string}\"";
                break;

            case 'sqlsrv':
                $this->query = "[{$string}]";
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
