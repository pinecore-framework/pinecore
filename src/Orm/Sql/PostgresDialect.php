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

    public function isConnectionLost(\PDOException $e): bool
    {
        // SQLSTATE class 08 = connection exception; 57P01/02/03 = admin shutdown,
        // crash shutdown, cannot connect now. pdo_pgsql exposes SQLSTATE via getCode().
        $sqlState = $e->getCode();
        if (is_string($sqlState) && $sqlState !== '') {
            if (str_starts_with($sqlState, '08')) {
                return true;
            }
            if (in_array($sqlState, ['57P01', '57P02', '57P03'], true)) {
                return true;
            }
        }

        // Connection-level failures often arrive as HY000/0 with the detail only
        // in the driver message — match the well-known pgsql phrases.
        $message = $e->getMessage();
        foreach ([
            'server closed the connection',
            'no connection to the server',
            'connection not open',
            'terminating connection',
            'could not connect',
            'EOF detected',
            'server has gone away',
        ] as $needle) {
            if (stripos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
