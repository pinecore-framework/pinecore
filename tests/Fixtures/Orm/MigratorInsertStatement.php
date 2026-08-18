<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use PDOStatement;

/** Captures the schema_migrations INSERT and keeps MigratorPdo::$applied current. */
final class MigratorInsertStatement extends PDOStatement
{
    public function __construct(private readonly MigratorPdo $pdo)
    {
        // Intentionally skip parent constructor.
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->log[] = ['insert', $params];
        $this->pdo->applied[$params[0]] = $params[1];

        return true;
    }
}
