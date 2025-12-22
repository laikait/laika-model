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

namespace Laika\Model\Abstracts;

abstract class BuilderHelper
{
    /**
     * @var array $params
     */
    protected array $params = [];

    /**
     * Abstract sql Method
     * @return ?string
     */
    abstract public function sql(): ?string;

    /**
     * Get Additionl Params
     * @return array
     */
    public function params(): array
    {
        return $this->params;
    }

    ########################################################################
    /*--------------------------- INTERNAL API ---------------------------*/
    ########################################################################
    /**
     * MySql/MariaDB Set List
     * @param array $values Values
     * @return string Example: 'red','green'
     */
    protected function mysqlList(array $values): string
    {
        return "'" . implode("','", array_values($values)) . "'";
    }

    /**
     * SqLite Set List
     * @param string $column Column Name
     * @param array $values Values
     * @return string Example: column_name GLOB 'red' OR column_name GLOB 'green'
     */
    protected function sqliteSetList(string $column, array $values): string
    {
        return implode(' OR ', array_map(function ($val) use ($column) {
            return "{$column} = '{$val}'";
        }, array_values($values)));
    }

    /**
     * PgSQL Set List
     * @param string $column Column Name. Example: 'id'
     * @param array $values Values
     * @return string Example: ARRAY['red','green']
     */
    protected function pgsqlSetList(string $column, array $values): string
    {
        return "ARRAY['" . implode("','", array_values($values)) . "']";
    }

    /**
     * SQL Server Set List
     * @param string $column Column Name. Example: 'id'
     * @param array $values Values
     * @return string Example: column_name LIKE '%red%' OR column_name LIKE '%green%'
     */
    protected function sqlsrvSetList(string $column, array $values): string
    {
        return implode(' OR ', array_map(function ($val) use ($column) {
            return "{$column} LIKE '%{$val}%'";
        }, array_values($values)));
    }

    /**
     * Oracle Set List
     * @param array $values Values
     * @return string Example: red|green|yellow
     */
    protected function ociSetList(array $values): string
    {
        return implode('|', array_values($values));
    }

    /**
     * Firebird Set List
     * @param string $column Column Name. Example: 'id'
     * @param array $values Values
     * @return string Example: column_name CONTAINING 'red' OR column_name CONTAINING 'green'
     */
    protected function firebirdSetList(string $column, array $values): string
    {
        return implode(' OR ', array_map(function ($val) use ($column) {
            return "{$column} CONTAINING '{$val}'";
        }, array_values($values)));
    }
}