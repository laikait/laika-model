<?php

declare(strict_types=1);

namespace Laika\Model\Schema;

/**
 * Fluent Foreign Key Definition.
 */
class ForeignKeyDefinition
{
    private array $definition;

    /**
     * @param string      $column Column Name
     * @param string|null $name   Optional constraint name
     */
    public function __construct(string $column, ?string $name = null)
    {
        $this->definition = ['column' => $column];
        if ($name !== null) {
            $this->definition['name'] = $name ?: $column;
        }
    }

    /**
     * @param string $column Referance Columen Name
     * @return self
     */
    public function reference(string $column): self
    {
        $this->definition['referenceColumn'] = $column;
        return $this;
    }

    /**
     * @param string $table Referance Table Name
     * @return self
     */
    public function on(string $table): self
    {
        $this->definition['referenceTable'] = $table;
        return $this;
    }

    /**
     * @param string $action Action On Delete
     * @return self
     */
    public function onDelete(string $action): self
    {
        $this->definition['onDelete'] = strtoupper($action);
        return $this;
    }

    /**
     * @param string $action Action On Update
     * @return self
     */
    public function onUpdate(string $action): self
    {
        $this->definition['onUpdate'] = strtoupper($action);
        return $this;
    }

    /**
     * @param string $name Foreign Key Index Name
     * @return self
     */
    public function name(string $name): self
    {
        $this->definition['name'] = $name;
        return $this;
    }

    /**
     * Get Definitions
     * @return array
     */
    public function &getDefinition(): array
    {
        return $this->definition;
    }
}
