<?php

namespace Pinecore\Pinecore\Orm\Sql;

/**
 * Migration files matched against what schema_migrations says is applied:
 * what will run, what is already done, which applied files were edited
 * afterwards (an error) and which applied versions have no file on disk
 * (a warning — typical after a branch switch).
 */
final class PgMigrationPlan
{
    /**
     * @param list<PgMigration>                                    $pending    not applied yet, in apply order
     * @param list<string>                                         $skipped    already applied, checksum intact
     * @param array<string, array{stored: string, actual: string}> $mismatched applied files edited afterwards
     * @param list<string>                                         $missing    applied versions absent on disk
     */
    public function __construct(
        public readonly array $pending,
        public readonly array $skipped,
        public readonly array $mismatched,
        public readonly array $missing,
    ) {}

    /**
     * @param list<PgMigration>     $files   discovered files in apply order
     * @param array<string, string> $applied version => checksum from schema_migrations
     */
    public static function create(array $files, array $applied): self
    {
        $pending = $skipped = $mismatched = [];
        foreach ($files as $migration) {
            if (!array_key_exists($migration->version, $applied)) {
                $pending[] = $migration;
            } elseif ($applied[$migration->version] === $migration->checksum) {
                $skipped[] = $migration->version;
            } else {
                $mismatched[$migration->version] = [
                    'stored' => $applied[$migration->version],
                    'actual' => $migration->checksum,
                ];
            }
        }

        $onDisk = array_map(static fn(PgMigration $m): string => $m->version, $files);
        $missing = array_values(array_diff(array_keys($applied), $onDisk));
        sort($missing);

        return new self($pending, $skipped, $mismatched, $missing);
    }
}
