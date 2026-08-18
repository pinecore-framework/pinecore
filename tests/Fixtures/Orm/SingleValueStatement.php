<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use PDOStatement;

/** Single-row, single-column result (to_regclass, advisory locks). */
final class SingleValueStatement extends PDOStatement
{
    public function __construct(private readonly mixed $value)
    {
        // Intentionally skip parent constructor.
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->value;
    }
}
