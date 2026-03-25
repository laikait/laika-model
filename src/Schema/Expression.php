<?php

declare(strict_types=1);

namespace Laika\Model\Schema;

class Expression
{
    public function __construct(public readonly string $value) {}
    public function __toString(): string { return $this->value; }
}