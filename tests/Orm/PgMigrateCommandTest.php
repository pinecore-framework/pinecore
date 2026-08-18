<?php

namespace Tests\Pinecore\Pinecore\Orm;

use League\CLImate\CLImate;
use League\CLImate\Util\Writer\Buffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\Console\ConsoleInput;
use Pinecore\Pinecore\Console\ConsoleOutput;
use Pinecore\Pinecore\Orm\Console\PgMigrateCommand;
use Pinecore\Pinecore\Orm\Sql\PgMigrator;
use Tests\Pinecore\Pinecore\Fixtures\Orm\MigratorPdo;

/** Command-level behaviour: output, exit codes, and that --dry-run never writes. */
final class PgMigrateCommandTest extends TestCase
{
    private string $dir;
    private MigratorPdo $pdo;
    private Buffer $out;
    private Buffer $err;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pinecore-migrate-cmd-' . uniqid();
        mkdir($this->dir);
        $this->pdo = new MigratorPdo();
        $this->out = new Buffer();
        $this->err = new Buffer();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    #[Test]
    public function appliesPendingMigrationsAndReportsEachOne(): void
    {
        $this->write('20250101000000_first.sql', 'CREATE TABLE a (id text);');

        $exitCode = $this->runCommand(dryRun: false);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('applied 20250101000000_first', $this->out->get());
        self::assertStringContainsString('1 applied, 0 skipped', $this->out->get());
        self::assertCount(1, $this->pdo->executedMigrations());
    }

    #[Test]
    public function rerunIsANoOp(): void
    {
        $this->write('20250101000000_first.sql', 'CREATE TABLE a (id text);');

        $this->runCommand(dryRun: false);
        $exitCode = $this->runCommand(dryRun: false);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('0 applied, 1 skipped', $this->out->get());
        self::assertCount(1, $this->pdo->executedMigrations(), 'second run must not re-apply');
    }

    #[Test]
    public function dryRunPrintsThePlanWithoutWritingToTheDatabase(): void
    {
        $this->write('20250101000000_first.sql', 'CREATE TABLE a (id text);');
        $this->write('20250102000000_second.sql', "-- pinecore: no-transaction\nCREATE INDEX CONCURRENTLY i ON a (id);");

        $exitCode = $this->runCommand(dryRun: true);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('[pending] 20250101000000_first', $this->out->get());
        self::assertStringContainsString('[pending] 20250102000000_second (no transaction)', $this->out->get());
        self::assertStringContainsString('2 to apply, 0 already applied', $this->out->get());
        foreach ($this->pdo->log as $entry) {
            self::assertSame('query', $entry[0], 'dry-run may only read');
        }
    }

    #[Test]
    public function dryRunReportsSkippedAndMismatchedVersions(): void
    {
        $applied = 'CREATE TABLE a (id text);';
        $this->write('20250101000000_first.sql', $applied);
        $this->write('20250102000000_second.sql', 'CREATE TABLE b (id text);');
        $this->pdo->tableExists = true;
        $this->pdo->applied     = [
            '20250101000000_first'  => hash('sha256', $applied),
            '20250102000000_second' => 'checksum-of-the-original-file',
        ];

        $exitCode = $this->runCommand(dryRun: true);

        self::assertSame(1, $exitCode, 'mismatch must fail the dry-run');
        self::assertStringContainsString('[skip] 20250101000000_first (already applied)', $this->out->get());
        self::assertStringContainsString('[mismatch] 20250102000000_second', $this->err->get());
    }

    #[Test]
    public function checksumMismatchFailsTheRunWithoutApplyingAnything(): void
    {
        $this->write('20250101000000_first.sql', 'CREATE TABLE a (id text);');
        $this->pdo->tableExists = true;
        $this->pdo->applied     = ['20250101000000_first' => 'checksum-of-the-original-file'];

        $exitCode = $this->runCommand(dryRun: false);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Checksum mismatch', $this->err->get());
        self::assertSame([], $this->pdo->executedMigrations());
    }

    #[Test]
    public function failedMigrationReportsTheVersionAndExitsNonZero(): void
    {
        $this->write('20250101000000_broken.sql', 'CREATE TABLE broken;');
        $this->pdo->failOnSql = 'CREATE TABLE broken';

        $exitCode = $this->runCommand(dryRun: false);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Migration 20250101000000_broken failed', $this->err->get());
    }

    #[Test]
    public function appliedVersionWithoutFileIsWarnedAboutNotFatal(): void
    {
        $this->pdo->tableExists = true;
        $this->pdo->applied     = ['20240101000000_from_other_branch' => 'whatever'];

        $exitCode = $this->runCommand(dryRun: false);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('20240101000000_from_other_branch is recorded', $this->out->get());
        self::assertStringContainsString('file is missing', $this->out->get());
    }

    #[Test]
    public function missingConfigKeyFailsWithAHint(): void
    {
        $exitCode = $this->runCommand(dryRun: false, migrationsPath: null);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('orm.migrations_path', $this->err->get());
    }

    private function runCommand(bool $dryRun, ?string $migrationsPath = ''): int
    {
        $config = new Config(
            $migrationsPath === null
                ? []
                : ['orm' => ['migrations_path' => $migrationsPath === '' ? $this->dir : $migrationsPath]],
        );

        $command = new PgMigrateCommand($config, new PgMigrator($this->pdo));
        $input   = new ConsoleInput(
            command: 'pg:migrate',
            pathParams: [],
            arguments: [],
            options: ['dry-run' => $dryRun],
        );
        $output = $this->consoleOutput();

        $command($input, $output);

        return $output->getExitCode();
    }

    private function consoleOutput(): ConsoleOutput
    {
        $climate = new CLImate();
        $climate->output->add('out', $this->out);
        $climate->output->add('error', $this->err);
        $climate->output->defaultTo('out');

        return new ConsoleOutput($climate);
    }

    private function write(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/' . $name, $sql);
    }
}
