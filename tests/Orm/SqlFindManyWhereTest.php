<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\SqlHydrator;
use Tests\Pinecore\Pinecore\Fixtures\Orm\ExprRow;
use Tests\Pinecore\Pinecore\Fixtures\Orm\ExprRowRepository;
use Tests\Pinecore\Pinecore\Fixtures\Orm\LinkRow;
use Tests\Pinecore\Pinecore\Fixtures\Orm\LinkRowRepository;

/**
 * findManyWhere(): list queries built from the same pieces as findOneWhere()
 * (buildWhere, selectList, hydrateRows) plus ORDER BY / LIMIT / OFFSET. Data
 * behaviour round-trips on SQLite; the exact SQL — and that order-by input never
 * reaches it unvalidated — is asserted on a recording PDO.
 */
final class SqlFindManyWhereTest extends TestCase
{
    private LinkRowRepository $repo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite not available');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE links (object_id TEXT PRIMARY KEY, class_id TEXT NOT NULL, title TEXT)');

        $this->repo = self::linkRepo($pdo);
        $this->repo->saveAll([
            new LinkRow('o3', 'c1', 'Gamma'),
            new LinkRow('o1', 'c1', 'Alpha'),
            new LinkRow('o2', 'c2', 'Beta'),
            new LinkRow('o4', 'c1'),
        ]);
    }

    #[Test]
    public function returnsOnlyMatchingRowsAsEntities(): void
    {
        $rows = $this->repo->listWhere(['class_id' => 'c1'], ['object_id' => 'asc']);

        self::assertContainsOnlyInstancesOf(LinkRow::class, $rows);
        self::assertSame(['o1', 'o3', 'o4'], self::ids($rows));
        self::assertSame('Alpha', $rows[0]->title);
    }

    #[Test]
    public function noMatchYieldsEmptyList(): void
    {
        self::assertSame([], $this->repo->listWhere(['class_id' => 'nope']));
    }

    #[Test]
    public function emptyWhereReturnsWholeTable(): void
    {
        self::assertCount(4, $this->repo->listWhere([]));
    }

    #[Test]
    public function nullFilterBecomesIsNull(): void
    {
        self::assertSame(['o4'], self::ids($this->repo->listWhere(['title' => null])));
    }

    #[Test]
    public function ordersByColumnOrPropertyNameAlike(): void
    {
        $byColumn   = $this->repo->listWhere([], ['class_id' => 'desc', 'object_id' => 'asc']);
        $byProperty = $this->repo->listWhere([], ['classId' => 'DESC', 'objectId' => 'Asc']);

        self::assertSame(['o2', 'o1', 'o3', 'o4'], self::ids($byColumn));
        self::assertSame(self::ids($byColumn), self::ids($byProperty));
    }

    #[Test]
    public function columnAndPropertyKeysProduceIdenticalSql(): void
    {
        $pdo  = new ManyRecordingPdo();
        $repo = self::linkRepo($pdo);

        $repo->listWhere(['class_id' => 'c1'], ['class_id' => 'desc', 'object_id' => 'asc'], 10, 20);
        $repo->listWhere(['class_id' => 'c1'], ['classId' => 'DESC', 'objectId' => 'asc'], 10, 20);

        self::assertSame(
            'SELECT "object_id", "class_id", "title" FROM "links" WHERE "class_id" = :w0 '
            . 'ORDER BY "class_id" DESC, "object_id" ASC LIMIT 10 OFFSET 20',
            $pdo->prepared[0],
        );
        self::assertSame($pdo->prepared[0], $pdo->prepared[1]);
        self::assertSame(['w0' => 'c1'], $pdo->params[0]);
    }

    #[Test]
    public function limitAndOffsetWindowTheResult(): void
    {
        self::assertSame(['o1', 'o2'], self::ids($this->repo->listWhere([], ['object_id' => 'asc'], 2)));
        self::assertSame(['o3', 'o4'], self::ids($this->repo->listWhere([], ['object_id' => 'asc'], 2, 2)));
        self::assertSame(['o4'], self::ids($this->repo->listWhere([], ['object_id' => 'asc'], 5, 3)));
        self::assertSame([], self::ids($this->repo->listWhere([], ['object_id' => 'asc'], 5, 10)));
    }

    #[Test]
    public function offsetWithoutLimitEmitsBareOffset(): void
    {
        // Postgres accepts OFFSET on its own; SQLite would not, so this one is structural.
        $pdo = new ManyRecordingPdo();
        self::linkRepo($pdo)->listWhere([], [], null, 5);

        self::assertSame('SELECT "object_id", "class_id", "title" FROM "links" OFFSET 5', $pdo->prepared[0]);
    }

    #[Test]
    public function zeroLimitReturnsEmptyWithoutQuerying(): void
    {
        $pdo = new ManyRecordingPdo();

        self::assertSame([], self::linkRepo($pdo)->listWhere(['class_id' => 'c1'], [], 0));
        self::assertSame([], $pdo->prepared);
    }

    #[Test]
    public function zeroLimitStillValidatesOrderBy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo->listWhere([], ['nope' => 'asc'], 0);
    }

    #[Test]
    public function negativeLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$limit must be >= 0');
        $this->repo->listWhere([], [], -1);
    }

    #[Test]
    public function negativeOffsetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$offset must be >= 0');
        $this->repo->listWhere([], [], null, -1);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function injectionAttempts(): iterable
    {
        yield 'key with sql'          => [['object_id; DROP TABLE links' => 'asc'], 'unknown order-by key'];
        yield 'direction with sql'    => [['object_id' => 'asc; --'], "must be 'asc' or 'desc'"];
        yield 'unknown column'        => [['missing' => 'asc'], 'unknown order-by key'];
        yield 'quoted column name'    => [['"object_id"' => 'asc'], 'unknown order-by key'];
        yield 'empty direction'       => [['object_id' => ''], "must be 'asc' or 'desc'"];
    }

    #[Test]
    #[DataProvider('injectionAttempts')]
    public function orderByInputNeverReachesSql(array $orderBy, string $message): void
    {
        $pdo  = new ManyRecordingPdo();
        $repo = self::linkRepo($pdo);

        try {
            $repo->listWhere([], $orderBy);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame([], $pdo->prepared, 'no statement may be prepared from rejected input');
    }

    #[Test]
    public function readExpressionsApplyAsInFindOneWhere(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE expr_rows (id TEXT PRIMARY KEY, label TEXT NOT NULL, note TEXT)');

        $dialect = new PostgresDialect();
        $repo    = new ExprRowRepository($pdo, $dialect, new SqlHydrator($dialect), new IdGenerator());
        $repo->save(new ExprRow('e2', 'beta', 'n'));
        $repo->save(new ExprRow('e1', 'alpha'));

        $rows = $repo->allOrderedById();

        self::assertSame(['e1', 'e2'], array_map(static fn(ExprRow $r): string => $r->id, $rows));
        self::assertSame(['ALPHA', 'BETA'], array_map(static fn(ExprRow $r): string => $r->label, $rows));
        self::assertSame('n%', $rows[1]->note);
        self::assertSame('BETA', $repo->byLabel('beta')?->label, 'same expansion as findOneWhere');
    }

    /** @return list<string> */
    private static function ids(array $rows): array
    {
        return array_map(static fn(LinkRow $r): string => $r->objectId, $rows);
    }

    private static function linkRepo(PDO $pdo): LinkRowRepository
    {
        $dialect = new PostgresDialect();
        return new LinkRowRepository($pdo, $dialect, new SqlHydrator($dialect), new IdGenerator());
    }
}

/** Records prepared SQL and bound parameters; never touches a database. */
final class ManyRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $prepared = [];
    /** @var list<array<string, mixed>|null> */
    public array $params = [];

    public function __construct()
    {
        // Intentionally skip parent constructor — no real connection.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepared[] = $query;
        return new ManyRecordingStatement($this);
    }
}

final class ManyRecordingStatement extends PDOStatement
{
    public function __construct(private readonly ManyRecordingPdo $owner) {}

    public function execute(?array $params = null): bool
    {
        $this->owner->params[] = $params;
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}
