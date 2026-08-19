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

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
            $this->quoteIdentifier($table),
            implode(', ', $cols),
            implode(', ', $values),
            $this->quoteIdentifier($idColumn),
            implode(', ', $this->excludedAssignments($columns, $idColumn)),
        );
    }

    /**
     * `col = EXCLUDED.col` for every column except the conflict key.
     *
     * @param list<string> $columns
     * @return list<string>
     */
    private function excludedAssignments(array $columns, string $idColumn): array
    {
        $updates = [];
        foreach ($columns as $c) {
            if ($c === $idColumn) {
                continue;
            }
            $q         = $this->quoteIdentifier($c);
            $updates[] = "{$q} = EXCLUDED.{$q}";
        }

        return $updates;
    }

    public function upsertMany(string $table, array $columns, string $idColumn, int $rowCount): string
    {
        if ($columns === [] || $rowCount < 1) {
            throw new \InvalidArgumentException('upsertMany() needs at least one column and one row');
        }

        $tuples = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $tuples[] = '(' . implode(
                ', ',
                array_map(static fn(string $c): string => ":r{$i}_{$c}", $columns),
            ) . ')';
        }

        return sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO UPDATE SET %s',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $tuples),
            $this->quoteIdentifier($idColumn),
            implode(', ', $this->excludedAssignments($columns, $idColumn)),
        );
    }

    public function maxBindParameters(): int
    {
        // Postgres' wire protocol caps parameters per statement at 2^16-1.
        return 65535;
    }

    public function vectorLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(static fn($v): float => (float) $v, $vector)) . ']';
    }

    public function arrayLiteral(array $items): string
    {
        $encoded = [];
        foreach ($items as $item) {
            if ($item === null) {
                throw new \InvalidArgumentException(
                    'text[] elements must not be null; drop the element instead of storing NULL inside the array'
                );
            }
            if (!is_string($item)) {
                throw new \InvalidArgumentException(
                    'text[] elements must be strings, got ' . get_debug_type($item)
                );
            }
            // Always quoted, so only backslash and the quote itself need escaping.
            $encoded[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $item) . '"';
        }

        return '{' . implode(',', $encoded) . '}';
    }

    public function boolLiteral(bool $value): string
    {
        return $value ? 't' : 'f';
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
