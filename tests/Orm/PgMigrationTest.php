<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\Sql\PgMigration;

/** File parsing only — no database. */
final class PgMigrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pinecore-migration-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    #[Test]
    public function parsesVersionChecksumAndSql(): void
    {
        $sql = "CREATE TABLE users (id text PRIMARY KEY);\n";
        $migration = PgMigration::fromFile($this->write('20250810120000_create_users.sql', $sql));

        self::assertSame('20250810120000_create_users', $migration->version);
        self::assertSame(hash('sha256', $sql), $migration->checksum);
        self::assertSame($sql, $migration->sql);
        self::assertFalse($migration->noTransaction);
    }

    #[Test]
    public function noTransactionDirectiveOnFirstLineIsRecognised(): void
    {
        $migration = PgMigration::fromFile($this->write(
            '20250810120000_add_index.sql',
            "-- pinecore: no-transaction\nCREATE INDEX CONCURRENTLY idx_users_email ON users (email);\n",
        ));

        self::assertTrue($migration->noTransaction);
    }

    #[Test]
    public function directiveToleratesExtraWhitespaceAndCrlf(): void
    {
        $migration = PgMigration::fromFile($this->write(
            '20250810120000_vacuum.sql',
            "--   pinecore:   no-transaction  \r\nVACUUM ANALYZE users;\n",
        ));

        self::assertTrue($migration->noTransaction);
    }

    #[Test]
    public function directiveBelowTheFirstLineIsIgnored(): void
    {
        $migration = PgMigration::fromFile($this->write(
            '20250810120000_noop.sql',
            "SELECT 1;\n-- pinecore: no-transaction\n",
        ));

        self::assertFalse($migration->noTransaction);
    }

    #[Test]
    public function directiveIsExactNotAPrefix(): void
    {
        $migration = PgMigration::fromFile($this->write(
            '20250810120000_note.sql',
            "-- pinecore: no-transaction-support-note\nSELECT 1;\n",
        ));

        self::assertFalse($migration->noTransaction);
    }

    #[Test]
    public function filenameWithoutTimestampPrefixIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid migration filename/');

        PgMigration::fromFile($this->write('create_users.sql', 'SELECT 1;'));
    }

    #[Test]
    public function filenameWithoutShortNameIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid migration filename/');

        PgMigration::fromFile($this->write('20250810120000.sql', 'SELECT 1;'));
    }

    private function write(string $name, string $sql): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $sql);

        return $path;
    }
}
