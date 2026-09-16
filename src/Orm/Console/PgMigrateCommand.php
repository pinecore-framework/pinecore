<?php

namespace Pinecore\Pinecore\Orm\Console;

use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\Console\ConsoleInput;
use Pinecore\Pinecore\Console\ConsoleOutput;
use Pinecore\Pinecore\Orm\Sql\PgMigration;
use Pinecore\Pinecore\Orm\Sql\PgMigrationPlan;
use Pinecore\Pinecore\Orm\Sql\PgMigrator;

/**
 * pg:migrate — apply pending SQL migrations from config('orm.migrations_path').
 * --dry-run prints the plan (pending, skipped, checksum mismatches, missing
 * files) without touching the database. The run itself is PgMigrator's job.
 *
 * Deploy order: pg:migrate first (owns tables, columns, extensions), then
 * pg:ensure-indexes (indexes stay declarative in entity attributes).
 */
final class PgMigrateCommand
{
    public function __construct(
        private readonly Config $config,
        private readonly PgMigrator $migrator,
    ) {}

    public function __invoke(ConsoleInput $input, ConsoleOutput $output): void
    {
        $dir = $this->config->getString('orm.migrations_path', '');
        if ($dir === '') {
            $output->error('No migrations path configured (set orm.migrations_path in config)');
            $output->setExitCode(1);
            return;
        }

        $dryRun = (bool) $input->option('dry-run', false);

        try {
            if ($dryRun) {
                $this->printPlan($this->migrator->plan($dir), $output);
            } else {
                $this->migrate($dir, $output);
            }
        } catch (\RuntimeException $e) {
            $output->error($e->getMessage());
            $output->setExitCode(1);
        }
    }

    private function migrate(string $dir, ConsoleOutput $output): void
    {
        $plan = $this->migrator->migrate(
            $dir,
            static fn(PgMigration $migration) => $output->success("applied {$migration->version}"),
        );

        $this->warnAboutMissing($plan, $output);
        $output->info(count($plan->pending) . ' applied, ' . count($plan->skipped) . ' skipped');
    }

    private function printPlan(PgMigrationPlan $plan, ConsoleOutput $output): void
    {
        foreach ($plan->mismatched as $version => $checksums) {
            $output->error(
                "[mismatch] {$version}: applied with checksum {$checksums['stored']}, file is now {$checksums['actual']}"
            );
        }
        $this->warnAboutMissing($plan, $output);

        foreach ($plan->skipped as $version) {
            $output->comment("[skip] {$version} (already applied)");
        }
        foreach ($plan->pending as $migration) {
            $output->writeln("[pending] {$migration->version}" . ($migration->noTransaction ? ' (no transaction)' : ''));
        }

        $output->info(count($plan->pending) . ' to apply, ' . count($plan->skipped) . ' already applied');

        if ($plan->mismatched !== []) {
            $output->setExitCode(1);
        }
    }

    private function warnAboutMissing(PgMigrationPlan $plan, ConsoleOutput $output): void
    {
        foreach ($plan->missing as $version) {
            $output->warn("{$version} is recorded in schema_migrations but its file is missing (branch switch?)");
        }
    }
}
