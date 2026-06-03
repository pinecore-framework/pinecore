<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\IdGenerator;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\SqlHydrator;
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
}
