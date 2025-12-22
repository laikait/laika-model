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

class Decimal extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile DECIMAL Blueprint
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(string $driver)
    {
        switch ($driver) {
            case 'mysql':
                $this->query = "DECIMAL";
                $this->params['length'] = '8,2';
                break;

            case 'pgsql':
                $this->query = "DECIMAL";
                $this->params['length'] = '8,2';
                break;

            case 'sqlite':
                $this->query = "NUMERIC";
                $this->params['length'] = null;
                break;

            case 'sqlsrv':
                $this->query = "DECIMAL";
                $this->params['length'] = '8,2';
                break;

            case 'oci':
                $this->query = "NUMBER";
                $this->params['length'] = '8,2';
                break;

            case 'firebird':
                $this->query = "DECIMAL";
                $this->params['length'] = '8,2';
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
