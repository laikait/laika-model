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

class Enum extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile ENUM Blueprint
     * @param string $column Column Name. Example: 'mysql'
     * @param array $values Values for Enum. Example: ['yes', 'no']
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(string $column, array $values, string $driver)
    {
        switch ($driver) {
            case 'mysql':
                $this->query = "ENUM({$this->mysqlList($values)})";
                $this->params['length'] = null;
                $this->params['constraint'] = null;
                break;

            case 'pgsql':
                $this->query = "TEXT CONSTRAINT chk_{$column} CHECK ({$column} IN ({$this->mysqlList($values)}))";
                $this->params['length'] = null;
                $this->params['constraint'] = null;
                break;

            case 'sqlite':
                $this->query = "TEXT CHECK ({$column} IN ({$this->mysqlList($values)}))";
                $this->params['length'] = null;
                $this->params['constraint'] = null;
                break;

            case 'sqlsrv':
                $this->query = "NVARCHAR";
                $this->params['length'] = 'MAX';
                $this->params['constraint'] = "CONSTRAINT chk_{$column} CHECK ({$column} IN ({$this->mysqlList($values)}))";
                break;

            case 'oci':
                $this->query = "VARCHAR2";
                $this->params['length'] = 255;
                $this->params['constraint'] = "CONSTRAINT chk_{$column} CHECK ({$column} IN ({$this->mysqlList($values)}))";
                break;

            case 'firebird':
                $this->query = "VARCHAR";
                $this->params['length'] = 255;
                $this->params['constraint'] = "CHECK ({$column} IN ({$this->mysqlList($values)}))";
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
