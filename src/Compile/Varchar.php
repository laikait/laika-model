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

class Varchar extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile VARCHAR Blueprint
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(string $driver)
    {
        switch ($driver) {
            case 'mysql':
                $this->query = "VARCHAR";
                $this->params['length'] = 255;
                break;

            case 'pgsql':
                $this->query = "VARCHAR";
                $this->params['length'] = 255;
                break;

            case 'sqlite':
                $this->query = "VARCHAR";
                $this->params['length'] = 255;
                break;

            case 'sqlsrv':
                $this->query = "VARCHAR";
                $this->params['length'] = 255;
                break;

            case 'oci':
                $this->query = "VARCHAR2";
                $this->params['length'] = 255;
                break;

            case 'firebird':
                $this->query = "VARCHAR";
                $this->params['length'] = 255;
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
