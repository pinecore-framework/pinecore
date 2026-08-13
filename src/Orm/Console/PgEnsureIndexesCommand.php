<?php

namespace Pinecore\Pinecore\Orm\Console;

use PDO;
use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\Console\ConsoleInput;
use Pinecore\Pinecore\Console\ConsoleOutput;
use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\CompositeIndexMetadata;
use Pinecore\Pinecore\Orm\EntityMap;
use Pinecore\Pinecore\Orm\FieldMetadata;
use Pinecore\Pinecore\Orm\IndexMetadata;
use Pinecore\Pinecore\Orm\Sql\SqlDialect;

/**
 * Postgres counterpart of EnsureIndexesCommand. For each SqlEntity in
 * config('orm.entities') it creates:
 *   - a B-tree index per #[Index] (unique / DESC / partial honoured), or the
 *     method named by #[Index(using:)] — 'gist', 'gin', …,
 *   - a multi-column index per class-level #[CompositeIndex],
 *   - an HNSW index per #[Vector] column, with the opclass matching its metric,
 *   - a GiST index per #[Geometry] column (unless it declares index: false).
 *
 * All statements use CREATE INDEX IF NOT EXISTS, so re-running is idempotent.
 * TTL (#[Index(expireAfterSeconds:)]) has no SQL equivalent — it is reported and
 * skipped; expiry must be handled by a separate scheduled cleanup command.
 */
final class PgEnsureIndexesCommand
{
    /** Index methods PostgreSQL ships with; anything else is a typo, not a plugin. */
    private const METHODS = ['btree', 'hash', 'gist', 'spgist', 'gin', 'brin'];

    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo,
        private readonly SqlDialect $dialect,
    ) {}

    public function __invoke(ConsoleInput $input, ConsoleOutput $output): void
    {
        $entities = $this->config->getArray('orm.entities', []);
        if ($entities === []) {
            $output->error('No entities configured (set orm.entities in config)');
            $output->setExitCode(1);
            return;
        }

        $dryRun = (bool) ($input->options['dry-run'] ?? false);

        foreach ($entities as $class) {
            if (!is_a($class, SqlEntity::class, true)) {
                continue;
            }

            $map = EntityMap::of($class);
            if ($map->storageName === null) {
                continue;
            }

            foreach ($map->indexes as $idx) {
                if ($idx->expireAfterSeconds !== null) {
                    $output->comment(
                        "[skip] {$map->storageName}.{$idx->field}: TTL is not supported in SQL; "
                        . 'use a scheduled cleanup command instead'
                    );
                }
                $this->run($output, $dryRun, $this->singleColumnStatement($map->storageName, $idx));
            }

            foreach ($map->compositeIndexes as $composite) {
                $this->run($output, $dryRun, $this->compositeStatement($map->storageName, $composite));
            }

            foreach ($map->fields as $field) {
                if ($field->isVector) {
                    $this->run($output, $dryRun, $this->hnswStatement($map->storageName, $field));
                } elseif ($field->isGeometry && $field->geometryIndex) {
                    $this->run($output, $dryRun, $this->gistStatement($map->storageName, $field));
                }
            }
        }
    }

    private function run(ConsoleOutput $output, bool $dryRun, string $sql): void
    {
        if ($dryRun) {
            $output->comment("[dry-run] {$sql}");
            return;
        }

        $this->pdo->exec($sql);
        $output->success($sql);
    }

    private function singleColumnStatement(string $table, IndexMetadata $idx): string
    {
        $method = $this->method($idx->using, "{$table}.{$idx->field}");
        $name   = $idx->name ?? "idx_{$table}_{$idx->field}";
        $unique = $idx->unique ? 'UNIQUE ' : '';
        // Only B-tree has an ordered form; DESC on gist/gin is a syntax error.
        $direction = ($idx->direction < 0 && ($method === null || $method === 'btree')) ? ' DESC' : '';
        $partial   = $idx->sparse
            ? ' WHERE ' . $this->dialect->quoteIdentifier($idx->field) . ' IS NOT NULL'
            : '';

        return sprintf(
            'CREATE %sINDEX IF NOT EXISTS %s ON %s%s (%s%s)%s',
            $unique,
            $this->dialect->quoteIdentifier($name),
            $this->dialect->quoteIdentifier($table),
            $method === null ? '' : " USING {$method}",
            $this->dialect->quoteIdentifier($idx->field),
            $direction,
            $partial,
        );
    }

    private function compositeStatement(string $table, CompositeIndexMetadata $idx): string
    {
        $method = $this->method($idx->using, $table . '(' . implode(', ', $idx->columns) . ')');
        $name   = $idx->name ?? 'idx_' . $table . '_' . implode('_', $idx->columns);
        $unique = $idx->unique ? 'UNIQUE ' : '';

        return sprintf(
            'CREATE %sINDEX IF NOT EXISTS %s ON %s%s (%s)',
            $unique,
            $this->dialect->quoteIdentifier($name),
            $this->dialect->quoteIdentifier($table),
            $method === null ? '' : " USING {$method}",
            implode(', ', array_map([$this->dialect, 'quoteIdentifier'], $idx->columns)),
        );
    }

    private function gistStatement(string $table, FieldMetadata $field): string
    {
        return sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s USING gist (%s)',
            $this->dialect->quoteIdentifier("idx_{$table}_{$field->field}_gist"),
            $this->dialect->quoteIdentifier($table),
            $this->dialect->quoteIdentifier($field->field),
        );
    }

    private function hnswStatement(string $table, FieldMetadata $field): string
    {
        $opclass = match ($field->vectorMetric) {
            'cosine' => 'vector_cosine_ops',
            'l2'     => 'vector_l2_ops',
            'ip'     => 'vector_ip_ops',
            default  => throw new \InvalidArgumentException(
                "Unknown vector metric '{$field->vectorMetric}' on column '{$field->field}'"
            ),
        };

        return sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s USING hnsw (%s %s)',
            $this->dialect->quoteIdentifier("idx_{$table}_{$field->field}_hnsw"),
            $this->dialect->quoteIdentifier($table),
            $this->dialect->quoteIdentifier($field->field),
            $opclass,
        );
    }

    /**
     * The index method goes into DDL unquoted, so it is whitelisted rather than
     * escaped — this also turns a typo into an error instead of a syntax failure.
     */
    private function method(?string $using, string $where): ?string
    {
        if ($using === null) {
            return null;
        }

        $method = strtolower($using);
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException(
                "Unknown index method '{$using}' on {$where}; expected one of: " . implode(', ', self::METHODS)
            );
        }

        return $method;
    }
}
