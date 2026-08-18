<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\Sql\PgMigration;
use Pinecore\Pinecore\Orm\Sql\PgMigrationPlan;

/** Pure planning — no database, no filesystem. */
final class PgMigrationPlanTest extends TestCase
{
    #[Test]
    public function unappliedFilesArePendingInGivenOrder(): void
    {
        $first  = self::migration('20250101000000_first');
        $second = self::migration('20250102000000_second');

        $plan = PgMigrationPlan::create([$first, $second], []);

        self::assertSame([$first, $second], $plan->pending);
        self::assertSame([], $plan->skipped);
        self::assertSame([], $plan->mismatched);
        self::assertSame([], $plan->missing);
    }

    #[Test]
    public function appliedFileWithIntactChecksumIsSkipped(): void
    {
        $migration = self::migration('20250101000000_first');

        $plan = PgMigrationPlan::create([$migration], [$migration->version => $migration->checksum]);

        self::assertSame([], $plan->pending);
        self::assertSame(['20250101000000_first'], $plan->skipped);
    }

    #[Test]
    public function appliedFileEditedAfterwardsIsMismatched(): void
    {
        $migration = self::migration('20250101000000_first');

        $plan = PgMigrationPlan::create([$migration], [$migration->version => 'checksum-as-applied']);

        self::assertSame([], $plan->pending);
        self::assertSame(
            ['20250101000000_first' => ['stored' => 'checksum-as-applied', 'actual' => $migration->checksum]],
            $plan->mismatched,
        );
    }

    #[Test]
    public function appliedVersionWithoutFileIsMissing(): void
    {
        $plan = PgMigrationPlan::create([], ['20240101000000_from_other_branch' => 'whatever']);

        self::assertSame(['20240101000000_from_other_branch'], $plan->missing);
        self::assertSame([], $plan->pending);
        self::assertSame([], $plan->mismatched);
    }

    #[Test]
    public function mixedStateIsClassifiedIndependently(): void
    {
        $applied  = self::migration('20250101000000_applied');
        $edited   = self::migration('20250102000000_edited');
        $pending  = self::migration('20250103000000_pending');

        $plan = PgMigrationPlan::create(
            [$applied, $edited, $pending],
            [
                $applied->version            => $applied->checksum,
                $edited->version             => 'old-checksum',
                '20240101000000_gone'        => 'whatever',
            ],
        );

        self::assertSame([$pending], $plan->pending);
        self::assertSame([$applied->version], $plan->skipped);
        self::assertSame([$edited->version], array_keys($plan->mismatched));
        self::assertSame(['20240101000000_gone'], $plan->missing);
    }

    private static function migration(string $version): PgMigration
    {
        $sql = "-- {$version}\nSELECT 1;";

        return new PgMigration($version, hash('sha256', $sql), $sql, false);
    }
}
