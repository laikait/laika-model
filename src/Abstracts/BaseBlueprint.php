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
use Laika\Model\Compile\Engine;
use Laika\Model\Compile\Charset;
use Laika\Model\Compile\Collate;
use Laika\Model\Blueprint\Column;

abstract class BaseBlueprint
{
    /**
     * @var string $driver
     */
    protected string $driver;

    /**
     * @var string $table
     */
    protected string $table;

    /**
     * @var array<string,object>
     */
    protected array $columns = [];

    /**
     * @var bool
     */
    protected bool $primaryDefined = false;

    /**
     * @var bool
     */
    protected bool $locked = false;

    /**
     * @var array<int,string>
     */
    protected array $indexes = [];

    /**
     * @var array<int|string> $checks
     */
    protected array $checks = [];

    /**
     * @var ?string
     */
    protected ?string $engine = null;

    /**
     * @var ?string
     */
    protected ?string $charset = null;

    /**
     * @var ?string
     */
    protected ?string $collation = null;

    /**
     * @var array $sqls
     */
    protected array $sqls = [];

    /**
     * Initiate Base Blueprint. Extend it in Laika\Model\Blueprint
     * @param string $table Table Name. Example: 'users'
     * @param string $drive Driver Name. Example: 'mysql'
     */
    public function __construct(string $table, string $drive)
    {
        $this->table = $table;
        $this->driver = $drive;
    }

    /**
     * Set Database Engine
     * @param ?string $engine Example: 'InnoDB'
     */
    public function engine(?string $engine = null): self
    {
        if ($this->locked) {
            throw new LogicException('Blueprint is Locked. No Further Mutation Allowed.');
        }

        $this->engine = $engine;
        return $this;
    }
    
    public function charset(?string $charset = null): self
    {
        if ($this->locked) {
            throw new LogicException('Blueprint is Locked. No Further Mutation Allowed.');
        }

        $this->charset = $charset;
        return $this;
    }
    
    public function collate(?string $collation = null): self
    {
        if ($this->locked) {
            throw new LogicException('Blueprint is Locked. No Further Mutation Allowed.');
        }

        $this->collation = $collation;
        return $this;
    }

    /**
     * Create Column
     * @return Column
     */
    public function column(string $name): Column
    {
        if ($this->locked) {
            throw new LogicException('Blueprint is locked. No further mutation allowed.');
        }

        $column = new Column($name, $this->driver, $this->table);

        // Detect primary key intent
        if (method_exists($column, 'isPrimary') && $column->isPrimary()) {
            if ($this->primaryDefined) {
                throw new LogicException('Only one PRIMARY KEY is allowed per table.');
            }
            $this->primaryDefined = true;
        }

        $this->columns[] = $column;
        return $column;
    }

    /**
     * Get SQL's
     * @return array
     */
    public function sqls(): array
    {
        return $this->sqls;
    }

    /**
     * Abstract Method
     * Make SQL Query
     * @return void
     */
    abstract public function create(): void;

    /**
     * Abstract Method
     * Alter SQL Query
     * @return void
     */
    abstract public function alter(): void;

    #######################################################################
    /*--------------------------- INTERNAL API ---------------------------*/
    #######################################################################
    protected function compileEngine(): string
    {
        return call_user_func([new Engine($this->engine, $this->driver), 'sql']);
    }

    protected function compileCharset(): string
    {
        return call_user_func([new Charset($this->charset, $this->driver), 'sql']);
    }

    protected function compileCollation(): string
    {
        return call_user_func([new Collate($this->collation, $this->driver), 'sql']);
    }    
}
