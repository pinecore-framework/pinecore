<?php

namespace Pinecore\Pinecore\Orm\Sql;

/**
 * Isolates the SQL differences between database engines. Postgres is the only
 * implementation today; a MySQL dialect can be added for the non-vector surface
 * (vectorLiteral/vectorDistance would throw there — pgvector is Postgres-only).
 */
interface SqlDialect
{
    public function quoteIdentifier(string $name): string;

    /**
     * Builds an idempotent upsert keyed on $idColumn. Named placeholders match
     * the column names verbatim (`:col`), so callers bind by column name.
     *
     * @param list<string> $columns
     */
    public function upsert(string $table, array $columns, string $idColumn): string;

    /**
     * Builds a multi-row variant of {@see upsert()} for $rowCount rows. Named
     * placeholders are `:r{index}_{column}`, so callers bind row-by-row.
     *
     * @param list<string> $columns
     */
    public function upsertMany(string $table, array $columns, string $idColumn, int $rowCount): string;

    /**
     * Maximum number of bound parameters one statement may carry. Batched writes
     * clamp their batch size to this / column-count.
     */
    public function maxBindParameters(): int;

    /**
     * Renders a float vector as a literal string bindable as a query parameter.
     */
    public function vectorLiteral(array $vector): string;

    /**
     * Renders a PHP bool as a literal string bindable as a query parameter.
     * PDO binds array-style execute() parameters as strings, and `false` casts to
     * '' — which no boolean column accepts — so the value has to be spelled out
     * in whatever the engine understands ('t'/'f' on Postgres, '1'/'0' elsewhere).
     */
    public function boolLiteral(bool $value): string;

    /**
     * SQL distance expression between stored $column and bound $param, using the
     * operator matching $metric ('cosine'|'l2'|'ip'). The operator MUST match the
     * index opclass or the ANN index is bypassed.
     */
    public function vectorDistance(string $column, string $param, string $metric): string;

    /**
     * Tells whether a PDOException reflects a lost/broken connection (server
     * restart, idle timeout, network drop) rather than a query-level error such
     * as a constraint violation. Used to recycle the worker instead of serving
     * errors from a dead cached connection.
     */
    public function isConnectionLost(\PDOException $e): bool;
}
