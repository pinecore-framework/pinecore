<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PDOStatement;
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\SqlHydrator;
use Pinecore\Pinecore\WorkerLifecycle;
use Tests\Pinecore\Pinecore\Fixtures\Orm\Document;
use Tests\Pinecore\Pinecore\Fixtures\Orm\DocumentRepository;

/**
 * Exercises the portable CRUD surface (persist/find/delete) against in-memory
 * SQLite using the real PostgresDialect — the upsert/quoting SQL is compatible.
 * findNearest is Postgres-only (pgvector operators) and is covered structurally
 * by PostgresDialectTest::vectorDistance*.
 */
final class AbstractSqlRepositoryTest extends TestCase
{
    private DocumentRepository $repo;
    private PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite not available');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE documents (
                id        TEXT PRIMARY KEY,
                tenant_id TEXT NOT NULL,
                title     TEXT NOT NULL,
                embedding TEXT,
                status    TEXT,
                metadata  TEXT
            )'
        );

        $dialect    = new PostgresDialect();
        $this->repo = new DocumentRepository($this->pdo, $dialect, new SqlHydrator($dialect), new IdGenerator());
    }

    #[Test]
    public function persistsAndFindsByIdWithTypedRoundTrip(): void
    {
        $saved = $this->repo->save(new Document('d1', 't1', 'Hello', [0.1, 0.2, 0.3], 'active', ['k' => 'v']));
        self::assertSame('d1', $saved->id);

        $found = $this->repo->find('d1');
        self::assertNotNull($found);
        self::assertSame('Hello', $found->title);
        self::assertSame([0.1, 0.2, 0.3], $found->embedding);
        self::assertSame(['k' => 'v'], $found->metadata);
        self::assertSame('active', $found->status);
    }

    #[Test]
    public function generatesIdWhenEmpty(): void
    {
        $saved = $this->repo->save(new Document('', 't1', 'x', [1.0, 2.0, 3.0]));

        self::assertNotSame('', $saved->id);
        self::assertNotNull($this->repo->find($saved->id));
    }

    #[Test]
    public function upsertUpdatesOnConflict(): void
    {
        $this->repo->save(new Document('d1', 't1', 'First', [1.0, 2.0, 3.0]));
        $this->repo->save(new Document('d1', 't1', 'Second', [4.0, 5.0, 6.0], 'done'));

        $found = $this->repo->find('d1');
        self::assertSame('Second', $found->title);
        self::assertSame('done', $found->status);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn());
    }

    #[Test]
    public function findOneWhereMatchesByColumn(): void
    {
        $this->repo->save(new Document('d1', 't1', 'Unique', [1.0, 2.0, 3.0]));

        self::assertSame('d1', $this->repo->byTitle('Unique')?->id);
        self::assertNull($this->repo->byTitle('Missing'));
    }

    #[Test]
    public function deleteRemovesRow(): void
    {
        $this->repo->save(new Document('d1', 't1', 'x', [1.0, 2.0, 3.0]));
        $this->repo->remove('d1');

        self::assertNull($this->repo->find('d1'));
    }

    #[Test]
    public function customSqlViaGuardedExecuteRuns(): void
    {
        $this->repo->save(new Document('d1', 't1', 'a', [1.0, 2.0, 3.0]));
        $this->repo->save(new Document('d2', 't1', 'b', [1.0, 2.0, 3.0]));
        $this->repo->save(new Document('d3', 't2', 'c', [1.0, 2.0, 3.0]));

        self::assertSame(2, $this->repo->deleteByTenant('t1'));
        self::assertNull($this->repo->find('d1'));
        self::assertNotNull($this->repo->find('d3'));
    }

    #[Test]
    public function customSqlResultsAreMappedThroughHydrationFacade(): void
    {
        $this->repo->save(new Document('d1', 't1', 'Beta', [1.0, 2.0, 3.0], 'active', ['k' => 'v']));
        $this->repo->save(new Document('d2', 't1', 'Alpha', [4.0, 5.0, 6.0]));
        $this->repo->save(new Document('d3', 't2', 'Gamma', [7.0, 8.0, 9.0]));

        $rows = $this->repo->byTenantOrdered('t1');

        self::assertCount(2, $rows);
        self::assertContainsOnlyInstancesOf(Document::class, $rows);
        self::assertSame(['Alpha', 'Beta'], array_map(static fn(Document $d): string => $d->title, $rows));
        // Typed round trip, not raw column strings.
        self::assertSame([4.0, 5.0, 6.0], $rows[0]->embedding);
        self::assertSame(['k' => 'v'], $rows[1]->metadata);
    }

    #[Test]
    public function singleRowFacadeReturnsTypedEntity(): void
    {
        $this->repo->save(new Document('d1', 't1', 'Only', [1.0, 2.0, 3.0], 'active'));

        $found = $this->repo->firstByTenant('t1');

        self::assertInstanceOf(Document::class, $found);
        self::assertSame('active', $found->status);
        self::assertSame([1.0, 2.0, 3.0], $found->embedding);
        self::assertNull($this->repo->firstByTenant('nobody'));
    }

    #[Test]
    public function dehydrationFacadeYieldsBindableColumns(): void
    {
        $columns = $this->repo->columnsOf(
            new Document('d1', 't1', 'Hello', [0.1, 0.2], 'active', ['k' => 'v'])
        );

        self::assertSame(
            ['id', 'tenant_id', 'title', 'embedding', 'status', 'metadata'],
            array_keys($columns),
        );
        self::assertSame('[0.1,0.2]', $columns['embedding'], 'vector must be a bindable literal');
        self::assertSame('{"k":"v"}', $columns['metadata']);
    }

    #[Test]
    public function customSqlKeepsConnectionLossGuard(): void
    {
        $lifecycle = new WorkerLifecycle();
        $repo      = $this->repoOnPdo(new ThrowingPdo('server closed the connection unexpectedly'), $lifecycle);

        $this->expectException(\PDOException::class);
        try {
            $repo->deleteByTenant('t1');   // raw SQL through protected execute()
        } finally {
            self::assertTrue($lifecycle->shouldShutdown(), 'custom SQL must stay reconnect-resistant');
        }
    }

    #[Test]
    public function lostConnectionFlagsWorkerForRecycle(): void
    {
        $lifecycle = new WorkerLifecycle();
        $repo      = $this->repoOnPdo(new ThrowingPdo('server closed the connection unexpectedly'), $lifecycle);

        $this->expectException(\PDOException::class);
        try {
            $repo->save(new Document('d1', 't1', 'x', [1.0, 2.0, 3.0]));
        } finally {
            self::assertTrue($lifecycle->shouldShutdown(), 'worker should be flagged for recycle');
        }
    }

    #[Test]
    public function queryErrorDoesNotRecycleWorker(): void
    {
        $lifecycle = new WorkerLifecycle();
        $repo      = $this->repoOnPdo(new ThrowingPdo('duplicate key value violates unique constraint'), $lifecycle);

        try {
            $repo->save(new Document('d1', 't1', 'x', [1.0, 2.0, 3.0]));
            self::fail('expected PDOException');
        } catch (\PDOException) {
            // expected — rethrown
        }

        self::assertFalse($lifecycle->shouldShutdown());
    }

    private function repoOnPdo(PDO $pdo, WorkerLifecycle $lifecycle): DocumentRepository
    {
        $dialect = new PostgresDialect();
        return new DocumentRepository($pdo, $dialect, new SqlHydrator($dialect), new IdGenerator(), $lifecycle);
    }
}

/** PDO double whose prepare() always throws, simulating a driver failure. */
final class ThrowingPdo extends PDO
{
    public function __construct(private readonly string $message)
    {
        // Intentionally skip parent constructor — no real connection.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new \PDOException($this->message);
    }
}
