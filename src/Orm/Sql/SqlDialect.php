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
     * Renders a float vector as a literal string bindable as a query parameter.
     */
    public function vectorLiteral(array $vector): string;

    /**
     * SQL distance expression between stored $column and bound $param, using the
     * operator matching $metric ('cosine'|'l2'|'ip'). The operator MUST match the
     * index opclass or the ANN index is bypassed.
     */
    public function vectorDistance(string $column, string $param, string $metric): string;
}
