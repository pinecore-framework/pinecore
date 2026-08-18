<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use PDO;
use PDOStatement;

/** Canned FETCH_KEY_PAIR result (SELECT version, checksum FROM schema_migrations). */
final class KeyPairStatement extends PDOStatement
{
    /** @param array<string, string> $pairs */
    public function __construct(private readonly array $pairs)
    {
        // Intentionally skip parent constructor.
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->pairs;
    }
}
