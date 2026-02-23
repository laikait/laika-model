<?php

declare(strict_types=1);

namespace Laika\Model\Schema;

/**
 * Fluent column modifier returned by Blueprint column methods.
 */
class ColumnDefinition
{
    private array $definition;

    public function __construct(array $definition)
    {
        $this->definition = $definition;
    }

    public function nullable(bool $value = true): self
    {
        $this->definition['nullable'] = $value;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->definition['default'] = $value;
        return $this;
    }

    public function unsigned(bool $value = true): self
    {
        $this->definition['unsigned'] = $value;
        return $this;
    }

    public function autoIncrement(bool $value = true): self
    {
        $this->definition['auto_increment'] = $value;
        return $this;
    }

    public function comment(string $comment): self
    {
        $this->definition['comment'] = $comment;
        return $this;
    }

    public function &getDefinition(): array
    {
        return $this->definition;
    }
}
