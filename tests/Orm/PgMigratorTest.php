<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\Sql\PgMigration;
use Pinecore\Pinecore\Orm\Sql\PgMigrator;
use Tests\Pinecore\Pinecore\Fixtures\Orm\MigratorPdo;

/**
 * Full runs against a recording PDO double — no server is touched. Real
 * migration files live in a per-test temp directory.
 */
final class PgMigratorTest extends TestCase
{
    private string $dir;
    private MigratorPdo $pdo;
    private PgMigrator $migrator;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pinecore-migrator-' . uniqid();
        mkdir($this->dir);
        $this->pdo      = new MigratorPdo();
        $this->migrator = new PgMigrator($this->pdo);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    #[Test]
    public function migrationsAreAppliedInLexicographicOrderEachInItsOwnTransaction(): void
    {
        // Written out of order on purpose — discovery must sort by filename.
        $this->write('20250102000000_second.sql', 'CREATE TABLE b (id text);');
        $this->write('20250101000000_first.sql', 'CREATE TABLE a (id text);');

        $appliedVersions = [];
        $plan = $this->migrator->migrate(
            $this->dir,
            function (PgMigration $m) use (&$appliedVersions): void {
                $appliedVersions[] = $m->version;
            },
        );

        self::assertSame(
            ['CREATE TABLE a (id text);', 'CREATE TABLE b (id text);'],
            $this->pdo->executedMigrations(),
        );
        self::assertSame(['20250101000000_first', '20250102000000_second'], $appliedVersions);
        self::assertSame(['begin', 'commit', 'begin', 'commit'], $this->pdo->transactionEvents());
        self::assertSame(2, count($plan->pending));
    }

    #[Test]
    public function eachMigrationIsRecordedWithItsChecksum(): void
    {
        $sql = 'CREATE TABLE a (id text);';
        $this->write('20250101000000_first.sql', $sql);

        $this->migrator->migrate($this->dir);

        self::assertSame([['20250101000000_first', hash('sha256', $sql)]], $this->pdo->insertedRows());
    }

    #[Test]
    public function schemaMigrationsTableIsCreatedIdempotently(): void
    {
        $this->migrator->migrate($this->dir);

        $ddl = $this->pdo->log[1];
        self::assertSame('exec', $ddl[0]);
        self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS schema_migrations', $ddl[1]);
    }

    #[Test]
    public function advisoryLockIsTakenFirstAndReleasedLast(): void
    {
        $this->write('20250101000000_first.sql', 'SELECT 1;');

        $this->migrator->migrate($this->dir);

        $first = $this->pdo->log[0];
        $last  = $this->pdo->log[count($this->pdo->log) - 1];
        self::assertSame('query', $first[0]);
        self::assertSame('SELECT pg_advisory_lock(' . PgMigrator::LOCK_KEY . ')', $first[1]);
        self::assertSame('query', $last[0]);
        self::assertSame('SELECT pg_advisory_unlock(' . PgMigrator::LOCK_KEY . ')', $last[1]);
    }

    #[Test]
    public function alreadyAppliedMigrationsAreSkippedAndRerunIsANoOp(): void
    {
        $this->write('20250101000000_first.sql', 'CREATE TABLE a (id text);');

        $this->migrator->migrate($this->dir);
        $countAfterFirstRun = count($this->pdo->executedMigrations());

        $plan = $this->migrator->migrate($this->dir);

        self::assertSame($countAfterFirstRun, count($this->pdo->executedMigrations()), 'rerun must not re-apply');
        self::assertSame([], $plan->pending);
        self::assertSame(['20250101000000_first'], $plan->skipped);
    }

    #[Test]
    public function checksumMismatchAbortsBeforeApplyingAnything(): void
    {
        $this->write('20250101000000_first.sql', 'CREATE TABLE a (id text);');
        $this->write('20250102000000_second.sql', 'CREATE TABLE b (id text);');
        $this->pdo->tableExists = true;
        $this->pdo->applied     = ['20250101000000_first' => 'checksum-of-the-original-file'];

        try {
            $this->migrator->migrate($this->dir);
            self::fail('expected a checksum-mismatch failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Checksum mismatch', $e->getMessage());
            self::assertStringContainsString('20250101000000_first', $e->getMessage());
        }

        self::assertSame([], $this->pdo->executedMigrations(), 'nothing may be applied on mismatch');
        self::assertSame([], $this->pdo->insertedRows());
    }

    #[Test]
    public function failedMigrationRollsBackAndStopsTheRun(): void
    {
        $this->write('20250101000000_first.sql', 'CREATE TABLE a (id text);');
        $this->write('20250102000000_second.sql', 'CREATE TABLE broken;');
        $this->write('20250103000000_third.sql', 'CREATE TABLE c (id text);');
        $this->pdo->failOnSql = 'CREATE TABLE broken';

        try {
            $this->migrator->migrate($this->dir);
            self::fail('expected the failing migration to abort the run');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Migration 20250102000000_second failed', $e->getMessage());
        }

        self::assertSame(['CREATE TABLE a (id text);'], $this->pdo->executedMigrations());
        self::assertSame(['begin', 'commit', 'begin', 'rollback'], $this->pdo->transactionEvents());
        self::assertSame([['20250101000000_first', hash('sha256', 'CREATE TABLE a (id text);')]], $this->pdo->insertedRows());
    }

    #[Test]
    public function advisoryLockIsReleasedEvenWhenAMigrationFails(): void
    {
        $this->write('20250101000000_first.sql', 'CREATE TABLE broken;');
        $this->pdo->failOnSql = 'CREATE TABLE broken';

        try {
            $this->migrator->migrate($this->dir);
        } catch (\RuntimeException) {
        }

        $last = $this->pdo->log[count($this->pdo->log) - 1];
        self::assertSame(['query', 'SELECT pg_advisory_unlock(' . PgMigrator::LOCK_KEY . ')'], $last);
    }

    #[Test]
    public function failedUnlockDoesNotMaskTheMigrationError(): void
    {
        // Connection died mid-migration: exec() fails AND the unlock in finally
        // fails too. The caller must still see the migration error, not the
        // unlock's PDOException (a throw from finally replaces the in-flight one).
        $this->write('20250101000000_first.sql', 'CREATE TABLE broken;');
        $this->pdo->failOnSql   = 'CREATE TABLE broken';
        $this->pdo->failOnQuery = 'pg_advisory_unlock';

        try {
            $this->migrator->migrate($this->dir);
            self::fail('expected the failing migration to abort the run');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Migration 20250101000000_first failed', $e->getMessage());
        }
    }

    #[Test]
    public function noTransactionDirectiveRunsTheMigrationOutsideATransaction(): void
    {
        $this->write(
            '20250101000000_concurrent_index.sql',
            "-- pinecore: no-transaction\nCREATE INDEX CONCURRENTLY idx ON a (id);",
        );

        $this->migrator->migrate($this->dir);

        self::assertSame([], $this->pdo->transactionEvents());
        self::assertCount(1, $this->pdo->executedMigrations());
        self::assertCount(1, $this->pdo->insertedRows());
    }

    #[Test]
    public function appliedVersionWithoutFileIsAWarningNotAnError(): void
    {
        $this->pdo->tableExists = true;
        $this->pdo->applied     = ['20240101000000_from_other_branch' => 'whatever'];

        $plan = $this->migrator->migrate($this->dir);

        self::assertSame(['20240101000000_from_other_branch'], $plan->missing);
    }

    #[Test]
    public function planDoesNotWriteAnything(): void
    {
        $this->write('20250101000000_first.sql', 'CREATE TABLE a (id text);');

        $plan = $this->migrator->plan($this->dir);

        self::assertSame(['20250101000000_first'], array_map(fn(PgMigration $m) => $m->version, $plan->pending));
        foreach ($this->pdo->log as $entry) {
            self::assertSame('query', $entry[0], 'plan() may only read');
        }
        self::assertSame([], $this->pdo->insertedRows());
    }

    #[Test]
    public function planWorksBeforeTheTableExists(): void
    {
        $this->write('20250101000000_first.sql', 'SELECT 1;');

        $plan = $this->migrator->plan($this->dir);

        self::assertCount(1, $plan->pending);
        self::assertSame([], $plan->skipped);
    }

    #[Test]
    public function missingDirectoryIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Migrations directory not found/');

        $this->migrator->migrate($this->dir . '/nope');
    }

    private function write(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/' . $name, $sql);
    }
}
