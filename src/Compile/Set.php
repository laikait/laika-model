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

class Set extends BuilderHelper
{
    /**
     * @var ?string $query
     */
    protected ?string $query = '';

    /**
     * Compile SET Blueprint
     * @param string $column Column Name. Example: 'mysql'
     * @param array $values Values for Enum. Example: ['yes', 'no']
     * @param string $driver Driver Name. Example: 'mysql'
     * @return string
     */
    public function __construct(string $column, array $values, string $driver)
    {
        switch ($driver) {
            case 'mysql':
                $this->query = "SET({$this->mysqlList($values)})";
                $this->params['length'] = null;
                $this->params['constraint'] = null;
                break;

            case 'pgsql':
                $this->query = "TEXT[] CONSTRAINT chk_{$column} CHECK ({$column} <@ {$this->pgsqlSetList($column, $values)})";
                $this->params['length'] = null;
                $this->params['constraint'] = null;
                $this->params['pgsqlset'] = true;
                break;

            case 'sqlite':
                $this->query = "TEXT CHECK ({$this->sqliteSetList($column, $values)})";
                $this->params['length'] = null;
                $this->params['constraint'] = null;
                break;

            case 'sqlsrv':
                $this->query = "NVARCHAR";
                $this->params['length'] = "MAX";
                $this->params['constraint'] = "CONSTRAINT chk_{$column} CHECK ({$this->sqlsrvSetList($column, $values)})";
                break;

            case 'oci':
                $this->query = "VARCHAR2";
                $this->params['length'] = 255;
                $this->params['constraint'] = "CONSTRAINT chk_{$column} CHECK (REGEXP_LIKE({$column}, '({$this->ociSetList($values)})'))";
                break;

            case 'firebird':
                $this->query = "VARCHAR";
                $this->params['length'] = 255;
                $this->params['constraint'] = "CHECK ({$this->firebirdSetList($column, $values)})";
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
