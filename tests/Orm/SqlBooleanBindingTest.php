<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\SqlHydrator;
use Tests\Pinecore\Pinecore\Fixtures\Orm\ScalarRow;
use Tests\Pinecore\Pinecore\Fixtures\Orm\ScalarRowRepository;

/**
 * Writing booleans. PDO binds every value passed to execute() as a string, and
 * PHP's false casts to '' — which Postgres rejects on a boolean column and a text
 * column stores as an empty string. dehydrate() therefore spells booleans out
 * through the dialect, mirroring the 't'/'f' → bool coercion done on read.
 *
 * The round trips run on SQLite (dynamically typed), so what they pin down is
 * the value handed to the driver; that the literal is the one Postgres' boolean
 * parser accepts is asserted against PostgresDialect directly.
 */
final class SqlBooleanBindingTest extends TestCase
{
    private ScalarRowRepository $repo;
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE profiles (
                id         TEXT PRIMARY KEY,
                is_visible BOOLEAN NOT NULL,
                view_count INTEGER NOT NULL,
                score      REAL NOT NULL,
                is_pinned  BOOLEAN
            )'
        );

        $dialect    = new PostgresDialect();
        $this->repo = new ScalarRowRepository($this->pdo, $dialect, new SqlHydrator($dialect), new IdGenerator());
    }

    #[Test]
    public function dialectRendersBooleansPostgresUnderstands(): void
    {
        $dialect = new PostgresDialect();

        self::assertSame('t', $dialect->boolLiteral(true));
        self::assertSame('f', $dialect->boolLiteral(false));
    }

    #[Test]
    public function dehydrationNeverEmitsARawBool(): void
    {
        $columns = $this->repo->columnsOf(new ScalarRow('p1', false, 0, 0.0, true));

        // A raw false here is the defect: PDO would bind it as ''.
        self::assertSame('f', $columns['is_visible']);
        self::assertSame('t', $columns['is_pinned']);
        self::assertNotSame('', $columns['is_visible']);
    }

    #[Test]
    public function nullableBoolStaysNull(): void
    {
        $columns = $this->repo->columnsOf(new ScalarRow('p1', true, 0, 0.0, null));

        self::assertNull($columns['is_pinned'], 'null must not collapse into false');
    }

    #[Test]
    public function persistRoundTripsFalse(): void
    {
        $this->repo->save(new ScalarRow('p1', false, 3, 1.5, false));

        $found = $this->repo->find('p1');
        self::assertNotNull($found);
        self::assertSame(false, $found->isVisible);
        self::assertSame(false, $found->isPinned);
        self::assertSame(3, $found->viewCount);
        self::assertSame(1.5, $found->score);
    }

    #[Test]
    public function persistRoundTripsTrue(): void
    {
        $this->repo->save(new ScalarRow('p1', true, 0, 0.0, true));

        $found = $this->repo->find('p1');
        self::assertSame(true, $found?->isVisible);
        self::assertSame(true, $found?->isPinned);
    }

    #[Test]
    public function persistKeepsNullableBoolNullInTheDatabase(): void
    {
        $this->repo->save(new ScalarRow('p1', true, 0, 0.0, null));

        $stored = $this->pdo->query('SELECT is_pinned FROM profiles WHERE id = \'p1\'')->fetchColumn();
        self::assertNull($stored, 'NULL must reach the column as NULL, not as false');
        self::assertNull($this->repo->find('p1')?->isPinned);
    }

    #[Test]
    public function persistAllRoundTripsBothBooleanValues(): void
    {
        $written = $this->repo->saveAll([
            new ScalarRow('p1', false, 1, 0.5, false),
            new ScalarRow('p2', true, 2, 1.5, null),
        ]);

        self::assertSame(2, $written);
        self::assertSame(false, $this->repo->find('p1')?->isVisible);
        self::assertSame(false, $this->repo->find('p1')?->isPinned);
        self::assertSame(true, $this->repo->find('p2')?->isVisible);
        self::assertNull($this->repo->find('p2')?->isPinned);
    }

    /** The stored text is what a boolean column's input function has to parse. */
    #[Test]
    public function storedValueIsTheDialectLiteralNotAnEmptyString(): void
    {
        $this->repo->save(new ScalarRow('p1', false, 0, 0.0));

        $stored = $this->pdo->query('SELECT is_visible FROM profiles WHERE id = \'p1\'')->fetchColumn();
        self::assertSame('f', $stored);
        self::assertNotSame('', $stored, "'' is what PDO makes of a raw false — and Postgres rejects it");
    }

    #[Test]
    public function updatingFromTrueToFalsePersists(): void
    {
        $this->repo->save(new ScalarRow('p1', true, 0, 0.0, true));
        $this->repo->save(new ScalarRow('p1', false, 0, 0.0, false));

        $found = $this->repo->find('p1');
        self::assertSame(false, $found?->isVisible);
        self::assertSame(false, $found?->isPinned);
    }
}
