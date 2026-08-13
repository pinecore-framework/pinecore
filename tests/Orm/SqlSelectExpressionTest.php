<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\SqlHydrator;
use Tests\Pinecore\Pinecore\Fixtures\Orm\DocumentRepository;
use Tests\Pinecore\Pinecore\Fixtures\Orm\ExprRow;
use Tests\Pinecore\Pinecore\Fixtures\Orm\ExprRowRepository;
use Tests\Pinecore\Pinecore\Fixtures\Orm\GeoPlaceRepository;

/**
 * Reads go through an explicit column list instead of SELECT *, so #[Field(readExpr:)]
 * / #[Geometry] expressions can wrap a column (PostGIS returns HEXEWKB otherwise).
 * The PostGIS shape is asserted on generated SQL; the expansion itself round-trips
 * on SQLite with dialect-neutral expressions.
 */
final class SqlSelectExpressionTest extends TestCase
{
    #[Test]
    public function readExpressionsAreAppliedAndAliasedBackToTheColumn(): void
    {
        $pdo = new RecordingPdo();
        $this->geoRepo($pdo)->find('p1');

        self::assertSame(
            'SELECT "id", "source", "external_id", ST_AsEWKT("geom") AS "geom", "bbox" '
            . 'FROM "places" WHERE "id" = :id LIMIT 1',
            $pdo->prepared[0],
        );
    }

    #[Test]
    public function entityWithoutReadExpressionsSelectsItsColumnsVerbatim(): void
    {
        $pdo = new RecordingPdo();
        $this->documentRepo($pdo)->find('d1');

        self::assertSame(
            'SELECT "id", "tenant_id", "title", "embedding", "status", "metadata" '
            . 'FROM "documents" WHERE "id" = :id LIMIT 1',
            $pdo->prepared[0],
        );
    }

    #[Test]
    public function findOneWhereUsesTheSameColumnList(): void
    {
        $pdo = new RecordingPdo();
        $this->geoRepo($pdo)->bySource('osm');

        self::assertStringStartsWith(
            'SELECT "id", "source", "external_id", ST_AsEWKT("geom") AS "geom", "bbox" FROM "places"',
            $pdo->prepared[0],
        );
        self::assertStringContainsString('WHERE "source" = :w0', $pdo->prepared[0]);
    }

    #[Test]
    public function findNearestKeepsColumnListAlongsideTheDistanceExpression(): void
    {
        $pdo = new RecordingPdo();
        $this->documentRepo($pdo)->nearest([0.1, 0.2, 0.3], 5);

        self::assertStringStartsWith(
            'SELECT "id", "tenant_id", "title", "embedding", "status", "metadata", '
            . '"embedding" <=> :vec AS distance FROM "documents"',
            $pdo->prepared[0],
        );
    }

    #[Test]
    public function expressionsRoundTripThroughHydration(): void
    {
        $repo = $this->exprRepo();
        $repo->save(new ExprRow('e1', 'hello', 'note'));

        $found = $repo->find('e1');
        self::assertNotNull($found);
        self::assertSame('HELLO', $found->label, 'upper(%s) must be applied on read');
        self::assertSame('note%', $found->note);
    }

    /**
     * A literal '%' inside a read expression would explode sprintf; expansion
     * has to be a plain string replacement.
     */
    #[Test]
    public function readExpressionMayContainLiteralPercent(): void
    {
        $pdo = new RecordingPdo();
        $this->exprRepoOn($pdo)->find('e1');

        self::assertStringContainsString('"note" || \'%\' AS "note"', $pdo->prepared[0]);
    }

    #[Test]
    public function findOneWhereMatchesOnTheStoredValueNotTheExpression(): void
    {
        $repo = $this->exprRepo();
        $repo->save(new ExprRow('e1', 'hello'));

        // The filter binds against the raw column; only the projection is wrapped.
        self::assertSame('e1', $repo->byLabel('hello')?->id);
        self::assertNull($repo->byLabel('HELLO'));
    }

    private function exprRepo(): ExprRowRepository
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite not available');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE expr_rows (id TEXT PRIMARY KEY, label TEXT NOT NULL, note TEXT)');

        return $this->exprRepoOn($pdo);
    }

    private function exprRepoOn(PDO $pdo): ExprRowRepository
    {
        $dialect = new PostgresDialect();
        return new ExprRowRepository($pdo, $dialect, new SqlHydrator($dialect), new IdGenerator());
    }

    private function geoRepo(PDO $pdo): GeoPlaceRepository
    {
        $dialect = new PostgresDialect();
        return new GeoPlaceRepository($pdo, $dialect, new SqlHydrator($dialect), new IdGenerator());
    }

    private function documentRepo(PDO $pdo): DocumentRepository
    {
        $dialect = new PostgresDialect();
        return new DocumentRepository($pdo, $dialect, new SqlHydrator($dialect), new IdGenerator());
    }
}

/** PDO double that captures prepared SQL and yields an empty result set. */
final class RecordingPdo extends PDO
{
    /** @var list<string> */
    public array $prepared = [];

    public function __construct()
    {
        // Intentionally skip parent constructor — no real connection.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepared[] = $query;
        return new EmptyStatement();
    }
}

final class EmptyStatement extends PDOStatement
{
    public function __construct() {}

    public function execute(?array $params = null): bool
    {
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
