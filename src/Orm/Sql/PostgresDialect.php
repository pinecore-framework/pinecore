<?php

namespace Pinecore\Pinecore\Orm\Sql;

final class PostgresDialect implements SqlDialect
{
    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function upsert(string $table, array $columns, string $idColumn): string
    {
        $cols   = array_map([$this, 'quoteIdentifier'], $columns);
        $values = array_map(static fn(string $c): string => ':' . $c, $columns);

        $updates = [];
        foreach ($columns as $c) {
            if ($c === $idColumn) {
                continue;
            }
            $q         = $this->quoteIdentifier($c);
            $updates[] = "{$q} = EXCLUDED.{$q}";
        }

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
            $this->quoteIdentifier($table),
            implode(', ', $cols),
            implode(', ', $values),
            $this->quoteIdentifier($idColumn),
            implode(', ', $updates),
        );
    }

    public function vectorLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(static fn($v): float => (float) $v, $vector)) . ']';
    }

    public function vectorDistance(string $column, string $param, string $metric): string
    {
        $op = match ($metric) {
            'cosine' => '<=>',
            'l2'     => '<->',
            'ip'     => '<#>',
            default  => throw new \InvalidArgumentException("Unknown vector metric: {$metric}"),
        };

        return $this->quoteIdentifier($column) . ' ' . $op . ' ' . $param;
    }
}
