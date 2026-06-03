<?php

namespace Pinecore\Pinecore\Orm\Console;

use PDO;
use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\Console\ConsoleInput;
use Pinecore\Pinecore\Console\ConsoleOutput;
use Pinecore\Pinecore\Model\SqlEntity;
use Pinecore\Pinecore\Orm\EntityMap;
use Pinecore\Pinecore\Orm\FieldMetadata;
use Pinecore\Pinecore\Orm\IndexMetadata;
use Pinecore\Pinecore\Orm\Sql\SqlDialect;

/**
 * Postgres counterpart of EnsureIndexesCommand. For each SqlEntity in
 * config('orm.entities') it creates:
 *   - a B-tree index per #[Index] (unique / DESC / partial honoured),
 *   - an HNSW index per #[Vector] column, with the opclass matching its metric.
 *
 * All statements use CREATE INDEX IF NOT EXISTS, so re-running is idempotent.
 * TTL (#[Index(expireAfterSeconds:)]) has no SQL equivalent — it is reported and
 * skipped; expiry must be handled by a separate scheduled cleanup command.
 */
final class PgEnsureIndexesCommand
{
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
                $this->run($output, $dryRun, $this->btreeStatement($map->storageName, $idx));
            }

            foreach ($map->fields as $field) {
                if ($field->isVector) {
                    $this->run($output, $dryRun, $this->hnswStatement($map->storageName, $field));
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

    private function btreeStatement(string $table, IndexMetadata $idx): string
    {
        $name      = $idx->name ?? "idx_{$table}_{$idx->field}";
        $unique    = $idx->unique ? 'UNIQUE ' : '';
        $direction = $idx->direction < 0 ? ' DESC' : '';
        $partial   = $idx->sparse
            ? ' WHERE ' . $this->dialect->quoteIdentifier($idx->field) . ' IS NOT NULL'
            : '';

        return sprintf(
            'CREATE %sINDEX IF NOT EXISTS %s ON %s (%s%s)%s',
            $unique,
            $this->dialect->quoteIdentifier($name),
            $this->dialect->quoteIdentifier($table),
            $this->dialect->quoteIdentifier($idx->field),
            $direction,
            $partial,
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
}
