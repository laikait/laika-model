<?php

/**
 * Laika Database Model
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika Laika Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Model;

use LogicException;
use Laika\Model\Compile\Quote;
use Laika\Model\Abstracts\BaseBlueprint;

class Blueprint extends BaseBlueprint
{
    ######################################################################
    /*--------------------------- PUBLIC API ---------------------------*/
    ######################################################################
    
    public function __construct(string $table, string $driver)
    {
        parent::__construct($table, $driver);
    }

    /**
     * Make Table SQL Query
     * @return string
     */
    public function create(): void
    {
        if ($this->locked) {
            throw new LogicException("SQL Already Generated for This Blueprint.");
        }
        
        if (empty($this->columns)) {
            throw new LogicException("No Columns Defined for Table [{$this->table}]");
        }
        
        // Lock Blueprint
        $this->locked = true;

        // Create Table SQL
        $table = call_user_func([new Quote($this->table, $this->driver), 'sql']);
        $this->sqls[] = sprintf(
            "CREATE TABLE IF NOT EXISTS {$table} (\n    %s\n)%s%s%s",
            implode(",\n    ", $this->columns),
            $this->compileEngine(),
            $this->compileCharset(),
            $this->compileCollation()
        ).';';

        // Compile Indexes
        array_map(function ($col) {
            $keys = $col->getKey();
            $constraint = $col->constraint();
            // Add Keys
            if (!empty($keys)) {
                $this->sqls[] = $keys;
            }
            // Add Constraint
            if (!empty($constraint)) {
                $this->sqls[] = $constraint;
            }
        }, $this->columns);
    }

    /**
     * Add Column from Table
     * @return string
     */
    public function addColumn(): void
    {
        if ($this->locked) {
            throw new LogicException("SQL Already Generated for This Blueprint.");
        }
        
        if (empty($this->columns)) {
            throw new LogicException("No Columns Defined for ALTER TABLE [{$this->table}]");
        }
        
        $this->locked = true;
        
        // Generate ALTER TABLE Statement for Add Column
        $table = call_user_func([new Quote($this->table, $this->driver), 'sql']);

        array_map(function ($col) use($table) {
            $this->sqls[] = "ALTER TABLE {$table} ADD {$col};";
        }, $this->columns);

        // Compile Indexes
        array_map(function ($col) {
            $keys = $col->getKey();
            $constraint = $col->constraint();
            // Add Keys
            if (!empty($keys)) {
                $this->sqls[] = $keys;
            }
            // Add Constraint
            if (!empty($constraint)) {
                $this->sqls[] = $constraint;
            }
        }, $this->columns);
        return;
    }
}
