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

class Boolean extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile BOOLEAN Blueprint
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(string $driver)
    {
        switch ($driver) {
            case 'mysql':
                $this->query = "BOOLEAN";
                $this->params['length'] = null;
                break;

            case 'pgsql':
                $this->query = "BOOLEAN";
                $this->params['length'] = null;
                break;

            case 'sqlite':
                $this->query = "INTEGER";
                $this->params['length'] = null;
                break;

            case 'sqlsrv':
                $this->query = "BIT";
                $this->params['length'] = null;
                break;

            case 'oci':
                $this->query = "NUMBER";
                $this->params['length'] = 1;
                break;

            case 'firebird':
                $this->query = "BOOLEAN";
                $this->params['length'] = null;
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
