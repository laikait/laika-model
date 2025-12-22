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

abstract class ColumnBlueprint
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

    /*========================== NUMERIC TYPES ==========================*/
    /**
     * Decimal Type
     * @return self
     */
    public function decimal(): self
    {
        $compile = $this->compile->decimal($this->driver);
        $this->type = $compile->sql();
        $params = $compile->params();
        $this->length ??= $params['length']; // Default: "8,2" => "precision,scale"
        return $this;
    }

    /**
     * Float Type
     * @return self
     */
    public function float(): self
    {
        $compile = $this->compile->floatnumber($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /**
     * Double Type
     * @return self
     */
    public function double(): self
    {
        $compile = $this->compile->double($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /*========================== NUMERIC TYPES ==========================*/
    /**
     * Date Type
     * @return self
     */
    public function date(): self
    {
        $compile = $this->compile->date($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /**
     * Date Time Type
     * @return self
     */
    public function datetime(): self
    {
        $compile = $this->compile->datetime($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /**
     * Timestamp Type
     * @return self
     */
    public function timestamp(): self
    {
        $compile = $this->compile->timestamp($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /**
     * Time Type
     * @return self
     */
    public function time(): self
    {
        $compile = $this->compile->time($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /**
     * Year Type
     * @return self
     */
    public function year(): self
    {
        $compile = $this->compile->year($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /*========================== BOOLEAN TYPES ==========================*/
    /**
     * Boolean Type
     * @return self
     */
    public function boolean(): self
    {
        $compile = $this->compile->boolean($this->driver);
        $this->type = $compile->sql();
        $params = $compile->params();
        $this->length ??= $params['length'];
        $this->isBoolean = true;
        return $this;
    }

    /*======================== JSON/BINARY TYPES ========================*/
    /**
     * JSON Type
     * @return self
     */
    public function json(): self
    {
        $compile = $this->compile->json($this->driver);
        $this->type = $compile->sql();
        $params = $compile->params();
        $this->length ??= $params['length'];
        return $this;
    }

    /**
     * Blob Type
     * @return self
     */
    public function blob(): self
    {
        $compile = $this->compile->blob($this->driver);
        $this->type = $compile->sql();
        $params = $compile->params();
        $this->length ??= $params['length'];
        return $this;
    }

    /**
     * Long Blob Type
     * @return self
     */
    public function longblob(): self
    {
        $compile = $this->compile->longblob($this->driver);
        $this->type = $compile->sql();
        return $this;
    }

    /*========================== ENUM/SET TYPES ==========================*/
    /**
     * Enum Type
     * @return self
     */
    public function enum(array $values): self
    {
        
        if (empty($values)) {
            throw new LogicException('ENUM Requires at Least One Value.');
        }

        $compile = $this->compile->enum($this->column, $values, $this->driver);
        $this->type = $compile->sql();
        $params = $compile->params();
        $this->length ??= $params['length'];
        $this->constraint = $params['constraint'];
        
        return $this;
    }

    /**
     * Set Type
     * @return self
     */
    public function set(array $values): self
    {
        
        if (empty($values)) {
            throw new LogicException('SET Requires at Least One Value.');
        }

        $compile = $this->compile->set($this->column, $values, $this->driver);
        $this->type = $compile->sql();
        $params = $compile->params();
        $this->length ??= $params['length'];
        $this->constraint = $params['constraint'];
        $this->pgsqlSet = $params['pgsqlset'] ?? false;
        
        return $this;
    }

    /*========================== SPECIAL TYPES ==========================*/
    /**
     * Geometry Type
     * @return self
     */
    public function geometry(): self
    {
        $this->type = $this->compile->geometry($this->driver)->sql();
        $this->specialType = true;
        return $this;
    }

    /**
     * Point Type
     * @return self
     */
    public function point(): self
    {
        $this->type = $this->compile->point($this->driver)->sql();
        $this->specialType = true;
        return $this;
    }

    /**
     * Line String Type
     * @return self
     */
    public function linestring(): self
    {
        $this->specialType = true;
        $this->type = $this->compile->linestring($this->driver)->sql();
        return $this;
    }

    /**
     * Polygon Type
     * @return self
     */
    public function polygon(): self
    {
        $this->type = $this->compile->polygon($this->driver)->sql();
        $this->specialType = true;
        return $this;
    }

    /**
     * Multi Point Type
     * @return self
     */
    public function multipoint(): self
    {
        $this->type = $this->compile->multipoint($this->driver)->sql();
        $this->specialType = true;
        return $this;
    }

    /**
     * Multi Line String Type
     * @return self
     */
    public function multilinestring(): self
    {
        $this->type = $this->compile->multilinestring($this->driver)->sql();
        $this->specialType = true;
        return $this;
    }

    /**
     * Multi Polygon Type
     * @return self
     */
    public function multipolygon(): self
    {
        $this->type = $this->compile->multipolygon($this->driver)->sql();
        $this->specialType = true;
        return $this;
    }

    /*========================== CONSTRAINT ==========================*/
    /**
     * Constraint SQL
     * @return ?string
     */
    public function constraint(): ?string
    {
        return $this->constraint;
    }

    /**
     * Check SQL
     * @param string $expression Expression for CHECK
     * @return ?string
     */
    public function check(string $expression): ?string
    {
        return $this->check = $expression;
    }

    /*============================ OTHERS ============================*/
    /**
     * Get Key SQL
     * @return ?string
    */
    public function getKey(): ?string
    {
        return $this->key;
    }
    
    /**
     * Get Column Name
     * @return string
     */
    public function getName(): string
    {
        return $this->column;
    }

    /*============================ INDEX ============================*/
    /**
     * Define Primary Key
     * @return self
     */
    public function primary(): self
    {
        $this->primary = true;
        $this->null = false;
        $this->default = false;
        return $this;
    }

    /**
     * Define Unique Key
     * @return self
     */
    public function unique(): self
    {
        $this->key = $this->compile->unique($this->column, $this->table, $this->driver)->sql();
        $this->null = false;
        $this->default = false;
        return $this;
    }

    /**
     * Define Index Key
     * @return self
     */
    public function index(): self
    {
        $this->key = $this->compile->index($this->column, $this->table, $this->driver)->sql();
        return $this;
    }

    /**
     * Check Column is Primary
     * @return self
     */
    public function isPrimary(): bool
    {
        return $this->primary;
    }

    /**
     * Check Column is Unique
     * @return self
     */
    public function isUnique(): bool
    {
        return $this->unique;
    }

    /**
     * Check Column is Index
     * @return self
     */
    public function isIndex(): bool
    {
        return $this->index;
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
