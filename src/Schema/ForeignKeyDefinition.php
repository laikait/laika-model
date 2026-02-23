<?php

declare(strict_types=1);

namespace Laika\Model\Schema;

/**
 * Fluent Foreign Key Definition.
 */
class ForeignKeyDefinition
{
    private array $definition;

    public function __construct(string $column)
    {
        $this->definition = ['column' => $column];
    }

    public function references(string $column): self
    {
        $this->definition['referenceColumn'] = $column;
        return $this;
    }

    public function on(string $table): self
    {
        $this->definition['referenceTable'] = $table;
        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->definition['onDelete'] = strtoupper($action);
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->definition['onUpdate'] = strtoupper($action);
        return $this;
    }

    public function name(string $name): self
    {
        $this->definition['name'] = $name;
        return $this;
    }

    public function &getDefinition(): array
    {
        return $this->definition;
    }
}
