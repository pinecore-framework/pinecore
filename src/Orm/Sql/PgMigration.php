<?php

namespace Pinecore\Pinecore\Orm\Sql;

/**
 * One migration file: YYYYMMDDHHMMSS_short_name.sql, raw SQL applied as a whole
 * (up-only; rolling back a schema change is a new forward migration).
 *
 * The first line may carry a directive that disables the per-migration
 * transaction — required for statements PostgreSQL refuses to run inside a
 * transaction block (CREATE INDEX CONCURRENTLY, VACUUM, …):
 *
 *     -- pinecore: no-transaction
 */
final class PgMigration
{
    private const NAME_PATTERN      = '/^\d{14}_[A-Za-z0-9_]+$/';
    private const DIRECTIVE_PATTERN = '/^--\s*pinecore:\s*no-transaction\s*$/';

    public function __construct(
        public readonly string $version,
        public readonly string $checksum,
        public readonly string $sql,
        public readonly bool $noTransaction,
    ) {}

    public static function fromFile(string $path): self
    {
        $version = basename($path, '.sql');
        if (preg_match(self::NAME_PATTERN, $version) !== 1) {
            throw new \RuntimeException(
                "Invalid migration filename '" . basename($path) . "' (expected YYYYMMDDHHMMSS_short_name.sql)"
            );
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read migration file: {$path}");
        }

        return new self($version, hash('sha256', $sql), $sql, self::hasNoTransactionDirective($sql));
    }

    private static function hasNoTransactionDirective(string $sql): bool
    {
        $firstLine = strstr($sql, "\n", true);
        if ($firstLine === false) {
            $firstLine = $sql;
        }

        return preg_match(self::DIRECTIVE_PATTERN, rtrim($firstLine, "\r")) === 1;
    }
}
