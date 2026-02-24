<?php

/**
 * Laika Database Model
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Model;

class Log
{
    /**
     * @var array $queries
     */
    protected static array $queries = [];

    /**
     * Add Query
     * @param string|array $queries Queries to Add. Example: "SELECT * FROM users" or ["QUERY1", "QUERY2"]
     * @param string $connection PDO Connection Name. Example: 'default'
     * @return void
     */
    public static function add(string|array $queries, string $connection = 'default'): void
    {
        $queries = is_array($queries) ? $queries : [$queries];
        foreach ($queries as $query) {
            self::$queries[$connection][] = $query;
        }

        return;
    }

    /**
     * Get Queries
     * @return array
     */
    public static function get(): array
    {
        return self::$queries;
    }

    /**
     * Count Queries
     * @return int
     */
    public static function count(): int
    {
        return array_sum(array_map('count', self::$queries));
    }
}