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
 * persistAll() writes multi-row upserts. Exercised on SQLite (whose upsert
 * syntax matches the one PostgresDialect emits); the parameter-limit clamp is
 * asserted structurally against the dialect.
 */
final class SqlBatchWriteTest extends TestCase
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
    public function insertsWholeBatchAndReturnsRowCount(): void
    {
        $written = $this->repo->saveAll([
            $this->doc('d1', 'first'),
            $this->doc('d2', 'second'),
            $this->doc('d3', 'third'),
        ]);

        self::assertSame(3, $written);
        self::assertSame(3, $this->rowsInTable());
        self::assertSame('second', $this->repo->find('d2')?->title);
        self::assertSame([1.0, 2.0, 3.0], $this->repo->find('d3')?->embedding);
    }

    #[Test]
    public function existingRowsAreUpdatedNotDuplicated(): void
    {
        $this->repo->saveAll([$this->doc('d1', 'first'), $this->doc('d2', 'first')]);
        $this->repo->saveAll([$this->doc('d2', 'second'), $this->doc('d3', 'second')]);

        self::assertSame(3, $this->rowsInTable());
        self::assertSame('first', $this->repo->find('d1')?->title);
        self::assertSame('second', $this->repo->find('d2')?->title);
    }

    #[Test]
    public function inputLargerThanBatchSizeIsSplitAcrossStatements(): void
    {
        $docs = [];
        for ($i = 0; $i < 25; $i++) {
            $docs[] = $this->doc("d{$i}");
        }

        self::assertSame(25, $this->repo->saveAll($docs, batchSize: 4));
        self::assertSame(25, $this->rowsInTable());
        self::assertNotNull($this->repo->find('d24'));
    }

    #[Test]
    public function emptyInputWritesNothing(): void
    {
        self::assertSame(0, $this->repo->saveAll([]));
        self::assertSame(0, $this->rowsInTable());
    }

    #[Test]
    public function acceptsAGeneratorWithoutMaterialisingIt(): void
    {
        $generator = (function (): \Generator {
            yield $this->doc('g1');
            yield $this->doc('g2');
        })();

        self::assertSame(2, $this->repo->saveAll($generator, batchSize: 1));
        self::assertSame(2, $this->rowsInTable());
    }

    #[Test]
    public function missingIdsAreGenerated(): void
    {
        self::assertSame(2, $this->repo->saveAll([$this->doc(''), $this->doc('')]));

        $ids = $this->pdo->query('SELECT id FROM documents')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $ids);
        self::assertNotContains('', $ids);
        self::assertSame($ids, array_unique($ids), 'generated ids must be distinct');
    }

    /**
     * Postgres rejects a statement whose ON CONFLICT would touch the same row
     * twice, so duplicates inside one batch collapse to the last occurrence.
     */
    #[Test]
    public function duplicateIdsWithinOneBatchCollapseToTheLastOne(): void
    {
        $written = $this->repo->saveAll([
            $this->doc('d1', 'first'),
            $this->doc('d1', 'second'),
        ]);

        self::assertSame(1, $written);
        self::assertSame(1, $this->rowsInTable());
        self::assertSame('second', $this->repo->find('d1')?->title);
    }

    #[Test]
    public function duplicatesInSeparateBatchesStillUpsert(): void
    {
        $written = $this->repo->saveAll(
            [$this->doc('d1', 'first'), $this->doc('d1', 'second')],
            batchSize: 1,
        );

        self::assertSame(2, $written, 'each batch reports its own affected rows');
        self::assertSame(1, $this->rowsInTable());
        self::assertSame('second', $this->repo->find('d1')?->title);
    }

    #[Test]
    public function multiRowUpsertBindsPerRowPlaceholders(): void
    {
        $dialect = new PostgresDialect();

        self::assertSame(65535, $dialect->maxBindParameters(), "Postgres' per-statement parameter cap");
        self::assertSame(
            'INSERT INTO "documents" ("id", "title") VALUES (:r0_id, :r0_title), (:r1_id, :r1_title) '
            . 'ON CONFLICT ("id") DO UPDATE SET "title" = EXCLUDED."title"',
            $dialect->upsertMany('documents', ['id', 'title'], 'id', 2),
        );
    }

    /**
     * An oversized $batchSize must be clipped to maxBindParameters / columns,
     * otherwise the statement is rejected by the server rather than split.
     */
    #[Test]
    public function batchSizeIsClampedToTheDialectParameterLimit(): void
    {
        $recording = new BatchRecordingPdo($this->pdo);
        $dialect   = new TinyLimitDialect(8);   // 8 params / 6 columns => 1 row per statement
        $repo      = new DocumentRepository($recording, $dialect, new SqlHydrator($dialect), new IdGenerator());

        self::assertSame(3, $repo->saveAll([$this->doc('d1'), $this->doc('d2'), $this->doc('d3')], PHP_INT_MAX));

        $inserts = array_filter($recording->prepared, static fn(string $s): bool => str_starts_with($s, 'INSERT'));
        self::assertCount(3, $inserts, 'the clamp must split the input into single-row statements');
        self::assertSame(3, $this->rowsInTable());
    }

    private function doc(string $id, string $title = 'x'): Document
    {
        return new Document($id, 't1', $title, [1.0, 2.0, 3.0]);
    }

    private function rowsInTable(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
    }
}

/** Passes statements through to a real connection while recording their SQL. */
final class BatchRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $prepared = [];

    public function __construct(private readonly PDO $inner)
    {
        // Intentionally skip parent constructor — the inner PDO owns the connection.
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->prepared[] = $query;
        return $this->inner->prepare($query, $options);
    }
}

/** PostgresDialect behaviour with a deliberately tiny parameter budget. */
final class TinyLimitDialect implements \Pinecore\Pinecore\Orm\Sql\SqlDialect
{
    private readonly PostgresDialect $pg;

    public function __construct(private readonly int $limit)
    {
        $this->pg = new PostgresDialect();
    }

    public function maxBindParameters(): int
    {
        return $this->limit;
    }

    public function quoteIdentifier(string $name): string
    {
        return $this->pg->quoteIdentifier($name);
    }

    public function upsert(string $table, array $columns, string $idColumn): string
    {
        return $this->pg->upsert($table, $columns, $idColumn);
    }

    public function upsertMany(string $table, array $columns, string $idColumn, int $rowCount): string
    {
        return $this->pg->upsertMany($table, $columns, $idColumn, $rowCount);
    }

    public function vectorLiteral(array $vector): string
    {
        return $this->pg->vectorLiteral($vector);
    }

    public function arrayLiteral(array $items): string
    {
        return $this->pg->arrayLiteral($items);
    }

    public function boolLiteral(bool $value): string
    {
        return $this->pg->boolLiteral($value);
    }

    public function vectorDistance(string $column, string $param, string $metric): string
    {
        return $this->pg->vectorDistance($column, $param, $metric);
    }

    public function isConnectionLost(\PDOException $e): bool
    {
        return $this->pg->isConnectionLost($e);
    }
}
