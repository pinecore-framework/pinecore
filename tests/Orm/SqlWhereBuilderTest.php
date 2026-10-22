<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\WorkerLifecycle;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\SqlHydrator;
use Tests\Pinecore\Pinecore\Fixtures\Orm\Document;
use Tests\Pinecore\Pinecore\Fixtures\Orm\DocumentRepository;
use Tests\Pinecore\Pinecore\Fixtures\Orm\ScalarRow;
use Tests\Pinecore\Pinecore\Fixtures\Orm\ScalarRowRepository;

/**
 * Equality filters shared by findOneWhere() and findNearest(). Two failure modes
 * are covered: a bound `false` reaching the driver as '' (same root cause as the
 * write path), and `col = NULL` — never true in SQL, so it used to return nothing
 * while matching rows existed.
 */
final class SqlWhereBuilderTest extends TestCase
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
        $this->repo = new ScalarRowRepository($this->pdo, $dialect, new SqlHydrator($dialect), new IdGenerator(), new WorkerLifecycle());
    }

    #[Test]
    public function filtersOnFalse(): void
    {
        $this->repo->save(new ScalarRow('hidden', false, 0, 0.0));
        $this->repo->save(new ScalarRow('shown', true, 0, 0.0));

        self::assertSame('hidden', $this->repo->firstWhere(['is_visible' => false])?->id);
    }

    #[Test]
    public function filtersOnTrue(): void
    {
        $this->repo->save(new ScalarRow('hidden', false, 0, 0.0));
        $this->repo->save(new ScalarRow('shown', true, 0, 0.0));

        self::assertSame('shown', $this->repo->firstWhere(['is_visible' => true])?->id);
    }

    #[Test]
    public function filtersOnNullWithIsNull(): void
    {
        $this->repo->save(new ScalarRow('unset', true, 0, 0.0, null));
        $this->repo->save(new ScalarRow('pinned', true, 0, 0.0, true));

        self::assertSame('unset', $this->repo->firstWhere(['is_pinned' => null])?->id);
    }

    #[Test]
    public function nullFilterDoesNotMatchRowsThatHaveAValue(): void
    {
        $this->repo->save(new ScalarRow('pinned', true, 0, 0.0, false));

        self::assertNull($this->repo->firstWhere(['is_pinned' => null]));
    }

    #[Test]
    public function nullAndValueClausesCombine(): void
    {
        $this->repo->save(new ScalarRow('a', true, 0, 0.0, null));
        $this->repo->save(new ScalarRow('b', false, 0, 0.0, null));

        $found = $this->repo->firstWhere(['is_pinned' => null, 'is_visible' => false]);
        self::assertSame('b', $found?->id);
    }

    #[Test]
    public function nullClauseBindsNoPlaceholder(): void
    {
        $pdo = new WhereRecordingPdo();
        $this->documentRepo($pdo)->firstWhere(['status' => null, 'title' => 'x']);

        self::assertStringContainsString('WHERE "status" IS NULL AND "title" = :w0', $pdo->prepared[0]);
        self::assertSame(['w0' => 'x'], $pdo->params[0], 'the IS NULL clause must not bind a parameter');
    }

    #[Test]
    public function booleanFilterIsBoundAsTheDialectLiteral(): void
    {
        $pdo = new WhereRecordingPdo();
        $this->documentRepo($pdo)->firstWhere(['status' => false]);

        self::assertSame(['w0' => 'f'], $pdo->params[0], "false must not be bound as ''");
    }

    #[Test]
    public function dateTimeFilterIsNormalisedLikeOnWrite(): void
    {
        $pdo = new WhereRecordingPdo();
        $when = new \DateTimeImmutable('2026-08-13 12:00:00', new \DateTimeZone('Europe/Moscow'));

        $this->documentRepo($pdo)->firstWhere(['status' => $when]);

        self::assertSame(['w0' => '2026-08-13T09:00:00.000000+00:00'], $pdo->params[0]);
    }

    #[Test]
    public function findNearestSharesTheSameFilterBuilder(): void
    {
        $pdo = new WhereRecordingPdo();
        $this->documentRepo($pdo)->nearestWhere([0.1, 0.2, 0.3], 5, ['status' => null, 'title' => false]);

        self::assertStringContainsString('WHERE "status" IS NULL AND "title" = :w0', $pdo->prepared[0]);
        self::assertSame('f', $pdo->params[0]['w0']);
        self::assertArrayHasKey('vec', $pdo->params[0], 'the vector parameter must still be bound');
    }

    #[Test]
    public function emptyFilterStillProducesNoWhereClause(): void
    {
        $pdo = new WhereRecordingPdo();
        $this->documentRepo($pdo)->firstWhere([]);

        self::assertStringNotContainsString('WHERE', $pdo->prepared[0]);
        self::assertSame([], $pdo->params[0]);
    }

    /** The remaining bypass — hand-written SQL — is closed by an explicit facade. */
    #[Test]
    public function customSqlCanNormaliseItsOwnParameters(): void
    {
        $this->repo->save(new ScalarRow('hidden', false, 0, 0.0));
        $this->repo->save(new ScalarRow('shown', true, 0, 0.0));

        self::assertSame(1, $this->repo->countVisible(false));
        self::assertSame(1, $this->repo->countVisible(true));
    }

    private function documentRepo(PDO $pdo): DocumentRepository
    {
        $dialect = new PostgresDialect();
        return new DocumentRepository($pdo, $dialect, new SqlHydrator($dialect), new IdGenerator(), new WorkerLifecycle());
    }
}

/** Captures both the SQL and the parameters actually handed to the driver. */
final class WhereRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $prepared = [];

    /** @var list<array<string, mixed>> */
    public array $params = [];

    public function __construct()
    {
        // Intentionally skip parent constructor — no real connection.
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->prepared[] = $query;
        return new CapturingStatement($this);
    }
}

final class CapturingStatement extends \PDOStatement
{
    public function __construct(private readonly WhereRecordingPdo $owner) {}

    public function execute(?array $params = null): bool
    {
        $this->owner->params[] = $params ?? [];
        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}
