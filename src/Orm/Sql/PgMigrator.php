<?php

namespace Pinecore\Pinecore\Orm\Sql;

use PDO;

/**
 * Applies raw-SQL migration files to PostgreSQL. Deliberately Pg-specific
 * (transactional DDL, advisory locks) — none of it goes through SqlDialect.
 *
 * Applied versions are recorded in schema_migrations (created here,
 * idempotently). Each migration commits in its own transaction together with
 * its bookkeeping row, so a failed migration rolls back whole while everything
 * applied before it stays applied. A file whose first line is
 * "-- pinecore: no-transaction" runs outside a transaction (for
 * CREATE INDEX CONCURRENTLY and friends).
 *
 * The whole run holds a blocking pg_advisory_lock with a fixed key, so two
 * deploys migrating concurrently serialize instead of racing. Advisory locks
 * are session-scoped: lock, migrations and unlock all use the one injected PDO.
 */
final class PgMigrator
{
    /** Advisory-lock key shared by every pinecore instance ('pine' in ASCII). */
    public const LOCK_KEY = 0x70696E65;

    private const TABLE_DDL = 'CREATE TABLE IF NOT EXISTS schema_migrations ('
        . 'version text PRIMARY KEY, '
        . 'checksum text NOT NULL, '
        . 'applied_at timestamptz NOT NULL DEFAULT now())';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Read-only plan: nothing is locked, created or applied. Works before
     * schema_migrations exists (everything on disk is then pending).
     */
    public function plan(string $migrationsDir): PgMigrationPlan
    {
        return PgMigrationPlan::create($this->discover($migrationsDir), $this->appliedChecksums());
    }

    /**
     * Apply all pending migrations. A checksum mismatch on an already-applied
     * file aborts the run before anything is applied.
     *
     * @param  callable(PgMigration): void|null $onApplied called after each migration commits
     * @throws \RuntimeException on mismatch or a failed migration (already-applied ones stay)
     */
    public function migrate(string $migrationsDir, ?callable $onApplied = null): PgMigrationPlan
    {
        $this->pdo->query('SELECT pg_advisory_lock(' . self::LOCK_KEY . ')');

        try {
            $this->pdo->exec(self::TABLE_DDL);

            $plan = $this->plan($migrationsDir);
            $this->rejectMismatches($plan);

            foreach ($plan->pending as $migration) {
                $this->apply($migration);
                if ($onApplied !== null) {
                    $onApplied($migration);
                }
            }

            return $plan;
        } finally {
            $this->pdo->query('SELECT pg_advisory_unlock(' . self::LOCK_KEY . ')');
        }
    }

    /** @return list<PgMigration> lexicographic by filename = apply order */
    private function discover(string $migrationsDir): array
    {
        if (!is_dir($migrationsDir)) {
            throw new \RuntimeException("Migrations directory not found: {$migrationsDir}");
        }

        $migrations = [];
        foreach (glob($migrationsDir . '/*.sql') ?: [] as $path) {
            $migrations[] = PgMigration::fromFile($path);
        }
        usort($migrations, static fn(PgMigration $a, PgMigration $b): int => strcmp($a->version, $b->version));

        return $migrations;
    }

    /** @return array<string, string> version => checksum */
    private function appliedChecksums(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        return $this->pdo
            ->query('SELECT version, checksum FROM schema_migrations')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private function tableExists(): bool
    {
        // to_regclass() resolves against search_path and returns NULL for an
        // unknown relation — lets plan() read state without creating the table.
        return $this->pdo->query("SELECT to_regclass('schema_migrations')")->fetchColumn() !== null;
    }

    private function apply(PgMigration $migration): void
    {
        if (!$migration->noTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // Multi-statement strings are fine: pdo_pgsql sends exec() as one batch.
            $this->pdo->exec($migration->sql);
            $this->recordApplied($migration);
            if (!$migration->noTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$migration->noTransaction) {
                $this->rollBackQuietly();
            }
            throw new \RuntimeException("Migration {$migration->version} failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function recordApplied(PgMigration $migration): void
    {
        $this->pdo
            ->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)')
            ->execute([$migration->version, $migration->checksum]);
    }

    private function rejectMismatches(PgMigrationPlan $plan): void
    {
        if ($plan->mismatched === []) {
            return;
        }

        throw new \RuntimeException(
            'Checksum mismatch for applied migration(s): ' . implode(', ', array_keys($plan->mismatched))
            . '. Applied migration files must not change — add a new migration instead. Nothing was applied.'
        );
    }

    private function rollBackQuietly(): void
    {
        try {
            $this->pdo->rollBack();
        } catch (\Throwable) {
            // rollBack() failing (dead connection) must not mask the original error.
        }
    }
}
