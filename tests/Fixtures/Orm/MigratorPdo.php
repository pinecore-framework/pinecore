<?php

namespace Tests\Pinecore\Pinecore\Fixtures\Orm;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO double for PgMigrator tests: records every call in order into $log and
 * answers the queries the migrator issues (advisory locks, to_regclass,
 * SELECT from schema_migrations). No real connection.
 *
 * Log entries: ['exec', sql] | ['query', sql] | ['insert', params]
 *            | ['begin'] | ['commit'] | ['rollback']
 */
final class MigratorPdo extends PDO
{
    /** @var list<array{0: string, 1?: mixed}> */
    public array $log = [];

    /** @var array<string, string> version => checksum, kept current by inserts */
    public array $applied = [];

    public bool $tableExists = false;

    /** Substring of migration SQL that makes exec() throw. */
    public ?string $failOnSql = null;

    public function __construct()
    {
        // Intentionally skip parent constructor — no real connection.
    }

    public function exec(string $statement): int|false
    {
        if ($this->failOnSql !== null && str_contains($statement, $this->failOnSql)) {
            throw new PDOException('syntax error at or near "boom"');
        }

        $this->log[] = ['exec', $statement];
        if (str_starts_with($statement, 'CREATE TABLE IF NOT EXISTS schema_migrations')) {
            $this->tableExists = true;
        }

        return 0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->log[] = ['query', $query];

        if (str_contains($query, 'to_regclass')) {
            return new SingleValueStatement($this->tableExists ? 'schema_migrations' : null);
        }
        if (str_contains($query, 'FROM schema_migrations')) {
            return new KeyPairStatement($this->applied);
        }

        return new SingleValueStatement(null);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new MigratorInsertStatement($this);
    }

    public function beginTransaction(): bool
    {
        $this->log[] = ['begin'];
        return true;
    }

    public function commit(): bool
    {
        $this->log[] = ['commit'];
        return true;
    }

    public function rollBack(): bool
    {
        $this->log[] = ['rollback'];
        return true;
    }

    /** @return list<string> migration SQL passed to exec(), CREATE TABLE excluded */
    public function executedMigrations(): array
    {
        $sql = [];
        foreach ($this->log as $entry) {
            if ($entry[0] === 'exec' && !str_starts_with($entry[1], 'CREATE TABLE IF NOT EXISTS schema_migrations')) {
                $sql[] = $entry[1];
            }
        }

        return $sql;
    }

    /** @return list<string> 'begin'/'commit'/'rollback' in order */
    public function transactionEvents(): array
    {
        $events = [];
        foreach ($this->log as $entry) {
            if (in_array($entry[0], ['begin', 'commit', 'rollback'], true)) {
                $events[] = $entry[0];
            }
        }

        return $events;
    }

    /** @return list<array{0: string, 1: string}> [version, checksum] rows inserted */
    public function insertedRows(): array
    {
        $rows = [];
        foreach ($this->log as $entry) {
            if ($entry[0] === 'insert') {
                $rows[] = $entry[1];
            }
        }

        return $rows;
    }
}
