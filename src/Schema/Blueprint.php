<?php

declare(strict_types=1);

namespace Laika\Model\Schema;

/**
 * Blueprint – fluent table column/constraint builder.
 *
 * Usage:
 *   $table->id();
 *   $table->string('name', 100)->nullable();
 *   $table->integer('age')->default(0);
 *   $table->timestamps();
 *   $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
 */
class Blueprint
{
    private string $table;
    private array  $columns     = [];
    private array  $primaryKey  = [];
    private array  $uniques     = [];
    private array  $indexes     = [];
    private array  $foreignKeys = [];
    private array  $options     = [];

    public function __construct(string $table, array $options = [])
    {
        $this->table   = $table;
        $this->options = $options;
    }

    // -----------------------------------------------------------------------
    // Integer types
    // -----------------------------------------------------------------------

    public function id(string $name = 'id'): ColumnDefinition
    {
        $this->primaryKey = [$name];
        return $this->addColumn('id', $name, ['auto_increment' => true, 'unsigned' => true]);
    }

    public function bigId(string $name = 'id'): ColumnDefinition
    {
        $this->primaryKey = [$name];
        return $this->addColumn('bigId', $name, ['auto_increment' => true, 'unsigned' => true]);
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn('integer', $name);
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $name);
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $name);
    }

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('tinyInteger', $name);
    }

    public function unsignedInteger(string $name): ColumnDefinition
    {
        return $this->integer($name)->unsigned();
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->bigInteger($name)->unsigned();
    }

    // -----------------------------------------------------------------------
    // Decimal / float
    // -----------------------------------------------------------------------

    public function float(string $name): ColumnDefinition
    {
        return $this->addColumn('float', $name);
    }

    public function double(string $name): ColumnDefinition
    {
        return $this->addColumn('double', $name);
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('decimal', $name, ['precision' => $precision, 'scale' => $scale]);
    }

    // -----------------------------------------------------------------------
    // Boolean
    // -----------------------------------------------------------------------

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn('boolean', $name);
    }

    // -----------------------------------------------------------------------
    // String / text
    // -----------------------------------------------------------------------

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('string', $name, ['length' => $length]);
    }

    public function enum(string $name, array $values): ColumnDefinition
    {
        if (empty($values)) {
            throw new \InvalidArgumentException("Enum values cannot be empty for column [{$name}].");
        }
        return $this->addColumn('enum', $name, ['values' => array_values($values)]);
    }

    public function set(string $name, array $values): ColumnDefinition
    {
        if (empty($values)) {
            throw new \InvalidArgumentException("Set values cannot be empty for column [{$name}].");
        }
        return $this->addColumn('set', $name, ['values' => array_values($values)]);
    }

    public function serialize(string $name): ColumnDefinition
    {
        return $this->addColumn('text', $name);
    }

    public function char(string $name, int $length = 36): ColumnDefinition
    {
        return $this->addColumn('char', $name, ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn('text', $name);
    }

    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn('mediumText', $name);
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn('longText', $name);
    }

    // -----------------------------------------------------------------------
    // Date / time
    // -----------------------------------------------------------------------

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn('date', $name);
    }

    public function time(string $name): ColumnDefinition
    {
        return $this->addColumn('time', $name);
    }

    public function dateTime(string $name): ColumnDefinition
    {
        return $this->addColumn('dateTime', $name);
    }

    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn('timestamp', $name);
    }

    /** Add created_at and updated_at nullable timestamp columns. */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable()->default(null);
        $this->timestamp('updated_at')->nullable()->default(null);
    }

    /** Add deleted_at nullable timestamp column for soft deletes. */
    public function deleted(string $column = 'deleted_at'): ColumnDefinition
    {
        $res = $this->timestamp($column)->nullable();
        $this->index($column);
        return $res;
    }

    // -----------------------------------------------------------------------
    // Other types
    // -----------------------------------------------------------------------

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn('json', $name);
    }

    public function binary(string $name): ColumnDefinition
    {
        return $this->addColumn('binary', $name);
    }

    public function uid(string $name = 'uid'): ColumnDefinition
    {
        $res = $this->addColumn('uid', $name);
        $this->unique($name);
        return $res;
    }

    // -----------------------------------------------------------------------
    // Constraints
    // -----------------------------------------------------------------------

    public function primary(array $columns): self
    {
        $this->primaryKey = $columns;
        return $this;
    }

    public function unique(array|string $columns, ?string $name = null): self
    {
        $columns = (array)$columns;
        $this->uniques[] = ['columns' => $columns, 'name' => $name];
        return $this;
    }

    public function index(array|string $columns, ?string $name = null): self
    {
        $columns = (array)$columns;
        $this->indexes[] = ['columns' => $columns, 'name' => $name];
        return $this;
    }

    public function foreign(string $column): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($column);
        $this->foreignKeys[] = &$fk->getDefinition();
        return $fk;
    }

    // -----------------------------------------------------------------------
    // Getters
    // -----------------------------------------------------------------------

    public function getTable(): string       { return $this->table; }
    public function getColumns(): array      { return $this->columns; }
    public function getPrimaryKey(): array   { return $this->primaryKey; }
    public function getUniques(): array      { return $this->uniques; }
    public function getIndexes(): array      { return $this->indexes; }
    public function getForeignKeys(): array  { return $this->foreignKeys; }
    public function getOption(string $key): mixed { return $this->options[$key] ?? null; }

    // -----------------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------------

    private function addColumn(string $type, string $name, array $extra = []): ColumnDefinition
    {
        $def = new ColumnDefinition(array_merge(['type' => $type, 'name' => $name], $extra));
        $this->columns[] = &$def->getDefinition();
        return $def;
    }
}
