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

use LogicException;
use Laika\Model\Compile\Compile;

abstract class AlterBlueprint
{
    /**
     * @var string $driver
     */
    protected string $driver;

    /**
     * @var string $column
     */
    protected string $column;

    /**
     * @var string $table
     */
    protected string $table;

    /**
     * @var Compile $compile
     */
    protected Compile $compile;

    /**
     * @var ?string $type
     */
    protected ?string $type = null;

    /**
     * @var bool $specialType
     */
    protected bool $specialType = false;

    /**
     * @var bool $primary
     */
    protected bool $primary = false;

    /**
     * @var bool $null
     */
    protected bool $null = false;

    /**
     * @var bool $auto
     */
    protected bool $auto = false;

    /**
     * @var int|string|null $length
     */
    protected int|string|null $length = null;

    /**
     * @var ?string $unsigned
     */
    protected ?string $unsigned = null;

    /**
     * @var mixed $default
     */
    protected mixed $default = false;

    /**
     * @var bool $unique
     */
    protected bool $unique = false;

    /**
     * @var bool $index
     */
    protected bool $index = false;

    /**
     * Constraint
     * @var ?string $constraint
     */
    protected ?string $constraint = null;

    /**
     * Key Type
     * @var ?string $key
     */
    protected ?string $key = null;

    /**
     * Add Condition to Column
     * @param ?string $check
     */
    protected ?string $check = null;

    /**
     * Is Boolean Type
     * @var bool $isBoolean
     */
    protected bool $isBoolean = false;

    /**
     * Is PgSql Set
     * @var bool $pgsqlSet
     */
    protected bool $pgsqlSet = false;

    ######################################################################
    /*--------------------------- PUBLIC API ---------------------------*/
    ######################################################################

    /**
     * Constructor
     * @param string $column Column Name
     * @param string $driver Database Driver
     * @param string $table Table Name
     */
    public function __construct(string $column, string $driver, string $table)
    {
        $this->column = trim($column);
        $this->driver = $driver;
        $this->table = $table;
        $this->compile = new Compile();
    }

    // Make SQL
    abstract public function __toString();

    /*========================== COMMON ATTRS ==========================*/
    /**
     * Null Attribute
     * @return self
     */
    public function null(): self
    {
        $this->null = ($this->primary || $this->unique || $this->auto) ? false : true;
        return $this;
    }

    /**
     * Length of Column Type
     * @param int|string|null Length of Column Type $length Default is null
     * @return self
     */
    public function length(int|string|null $length = null): self
    {
        $this->length = $length;
        return $this;
    }

    /**
     * Auto Increment
     * @return self
     */
    public function auto(): self
    {
        $this->auto = true;
        $this->primary = true;
        $this->null = false;
        $this->default = false;
        return $this;
    }

    /**
     * Set Default Value
     * @param mixed $default
     * @return self
     */
    public function default(mixed $default): self
    {
        $this->default = ($this->auto || $this->unique || $this->specialType) ? false : $default;
        return $this;
    }

    /**
     * Unsigned Attribute
     * @return self
     */
    public function unsigned(): self
    {
        $compile = $this->compile->unsigned($this->column, $this->driver);
        $this->unsigned = $compile->sql();
        return $this;
    }

    /*========================== INTEGER TYPES ==========================*/
    /**
     * Int Type
     * @return self
     */
    public function int(): self
    {
        $this->type = $this->compile->integer($this->driver)->sql();
        return $this;
    }

    /**
     * Tiny Int Type
     * @return self
     */
    public function tinyint(): self
    {
        $compile = $this->compile->tinyint($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /**
     * Small Int Type
     * @return self
     */
    public function smallint(): self
    {
        $compile = $this->compile->smallint($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /**
     * Medium Int Type
     * @return self
     */
    public function mediumint(): self
    {
        $compile = $this->compile->mediumint($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /**
     * Bigint Type
     * @return self
     */
    public function bigint(): self
    {
        $compile = $this->compile->bigint($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /*========================== STRING TYPES ==========================*/
    /**
     * Char Type
     * @return self
     */
    public function char(): self
    {
        $compile = $this->compile->char($this->driver);
        $this->type = $compile->sql();
        $this->length ??= 255;
        return $this;
    }

    /**
     * Varchar Type
     * @return self
     */
    public function varchar(): self
    {
        $compile = $this->compile->varchar($this->driver);
        $this->type = $compile->sql();
        $this->length ??= 255;
        return $this;
    }

    /**
     * Text Type
     * @return self
     */
    public function text(): self
    {
        $compile = $this->compile->text($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /**
     * Medium Text Type
     * @return self
     */
    public function mediumtext(): self
    {
        $compile = $this->compile->mediumtext($this->driver);
        $this->type = $compile->sql();
        $params = $compile->params();
        $this->length ??= $params['length'];
        return $this;
    }

    /**
     * Long Text Type
     * @return self
     */
    public function longtext(): self
    {
        $compile = $this->compile->longtext($this->driver);
        $this->type = $compile->sql();
        $params = $compile->params();
        $this->length ??= $params['length'];
        return $this;
    }

    #####################################################################
    /*-------------------------- INTERNAL API -------------------------*/
    #####################################################################
    /**
     * Compile Type SQL
     * @return string
     */
    protected function compileType(): string
    {
        if (!$this->type) {
            throw new LogicException("Column [{$this->column}] Type not Defined!");
        }
        return $this->type;
    }

    /**
     * Compile Primary Key SQL
     * @return string
     */
    protected function compilePrimary(): string
    {
        return $this->primary ? ' PRIMARY KEY' : '';
    }

    /**
     * Compile Length SQL
     * @return string
     */
    protected function compileLength(): string
    {
        return $this->length !== null ? "({$this->length})" : '';
    }

    /**
     * Compile Unsigned SQL
     * @return string
     */
    protected function compileUnsigned(): string
    {
        return $this->unsigned ? " {$this->unsigned}" : '';
    }

    /**
     * Compile Default SQL
     * @return string
     */
    protected function compileDefault(): string
    {
        if ($this->isBoolean) {
            // Check Default for Boolean
            if (!is_bool($this->default)) {
                throw new LogicException("Default Value for Boolean Column [{$this->column}] Must be Boolean!", 10100);
            }
            $compile = $this->compile->BooleanAttribute($this->default, $this->driver);
            return " DEFAULT {$compile->value()}";
        }
        if ($this->pgsqlSet) {
            // Check Default for Set
            if (!is_string($this->default)) {
                throw new LogicException("Default Value for Set Column [{$this->column}] Must be String!", 10100);
            }
            return " DEFAULT ARRAY['{$this->default}']";
        }
        if ($this->default === false) {
            return '';
        }
        // Compile Default for Special
        if ($this->specialType) {
            return '';
        }

        // Compile NULL for Default
        if ($this->default === null) {
            $this->null = true;
            return '';
        }

        if (is_string($this->default)) {
            return " DEFAULT '{$this->default}'";
        }

        // if (is_bool($this->default)) {
        //     return ' DEFAULT ' . ($this->default ? 1 : 0);
        // }

        return " DEFAULT {$this->default}";
    }

    /**
     * Compile Null SQL
     * @return string
     */
    protected function compileNull(): string
    {
        return $this->null ? ' NULL' : ' NOT NULL';
    }

    /**
     * Compile Auto Increment SQL
     * @return string
     */
    protected function compileAuto(): string
    {
        return $this->auto ? " {$this->compile->auto($this->driver)->sql()}" : '';
    }

    /**
     * Compile Check SQL
     * @return string
     */
    protected function compileCheck(): string
    {
        return $this->check ? " CHECK ({$this->check})" : '';
    }
}
