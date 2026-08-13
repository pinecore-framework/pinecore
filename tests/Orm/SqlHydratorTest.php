<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\SqlHydrator;
use Tests\Pinecore\Pinecore\Fixtures\Orm\Document;
use Tests\Pinecore\Pinecore\Fixtures\Orm\ScalarRow;
use Tests\Pinecore\Pinecore\Fixtures\Orm\TimestampedRow;

final class SqlHydratorTest extends TestCase
{
    private SqlHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new SqlHydrator(new PostgresDialect());
    }

    #[Test]
    public function dehydrateEncodesVectorAndJson(): void
    {
        $doc = new Document(
            id: 'doc1',
            tenantId: 't1',
            title: 'Hello',
            embedding: [0.1, 0.2, 0.3],
            status: 'active',
            metadata: ['len' => 5],
        );

        $row = $this->hydrator->dehydrate($doc);

        self::assertSame('doc1', $row['id']);
        self::assertSame('t1', $row['tenant_id']);          // #[Field(name:)] honoured
        self::assertSame('[0.1,0.2,0.3]', $row['embedding']); // vector literal
        self::assertSame('{"len":5}', $row['metadata']);      // json encoded
        self::assertSame('active', $row['status']);
    }

    #[Test]
    public function hydrateParsesVectorAndJsonFromRow(): void
    {
        $doc = $this->hydrator->hydrate(Document::class, [
            'id'        => 'doc1',
            'tenant_id' => 't1',
            'title'     => 'Hello',
            'embedding' => '[0.1,0.2,0.3]',
            'status'    => null,
            'metadata'  => '{"len":5}',
        ]);

        self::assertInstanceOf(Document::class, $doc);
        self::assertSame([0.1, 0.2, 0.3], $doc->embedding);
        self::assertSame(['len' => 5], $doc->metadata);
        self::assertNull($doc->status);
    }

    #[Test]
    public function roundTripPreservesData(): void
    {
        $original = new Document('doc1', 't1', 'Hello', [1.5, 2.5, 3.5], 'active', ['k' => 'v']);

        $restored = $this->hydrator->hydrate(Document::class, $this->hydrator->dehydrate($original));

        self::assertEquals($original, $restored);
    }

    #[Test]
    public function dehydrateNormalizesDateTimeToUtcAtom(): void
    {
        $row = $this->hydrator->dehydrate(new TimestampedRow(
            id: 'e1',
            createdAt: new \DateTimeImmutable('2026-06-03 15:00:00', new \DateTimeZone('Europe/Moscow')),
        ));

        // Moscow 15:00 (+03:00) → UTC 12:00, DATE_ATOM format.
        self::assertSame('2026-06-03T12:00:00+00:00', $row['created_at']);
        self::assertNull($row['deleted_at']);
    }

    #[Test]
    public function hydrateWrapsTimestampIntoUtcDateTimeImmutable(): void
    {
        $row = $this->hydrator->hydrate(TimestampedRow::class, [
            'id'         => 'e1',
            'created_at' => '2026-06-03 12:00:00+00',   // typical pgsql timestamptz text
            'deleted_at' => null,
        ]);

        self::assertInstanceOf(\DateTimeImmutable::class, $row->createdAt);
        self::assertSame('2026-06-03T12:00:00+00:00', $row->createdAt->format(\DATE_ATOM));
        self::assertSame('UTC', $row->createdAt->getTimezone()->getName());
        self::assertNull($row->deletedAt);
    }

    #[Test]
    public function dateTimeRoundTripPreservesInstant(): void
    {
        $original = new TimestampedRow(
            'e1',
            new \DateTimeImmutable('2026-01-15T08:30:00+05:00'),
            new \DateTimeImmutable('2026-02-01T00:00:00+00:00'),
        );

        $restored = $this->hydrator->hydrate(TimestampedRow::class, $this->hydrator->dehydrate($original));

        self::assertEquals($original->createdAt->getTimestamp(), $restored->createdAt->getTimestamp());
        self::assertEquals($original->deletedAt->getTimestamp(), $restored->deletedAt->getTimestamp());
    }

    #[Test]
    public function hydrateCoercesPostgresStringScalars(): void
    {
        // pdo_pgsql (ATTR_EMULATE_PREPARES=false) returns every column as text:
        // booleans as 't'/'f', numbers as numeric strings.
        $row = $this->hydrator->hydrate(ScalarRow::class, [
            'id'         => 'p1',
            'is_visible' => 'f',
            'view_count' => '42',
            'score'      => '3.5',
            'is_pinned'  => 't',
        ]);

        self::assertSame(false, $row->isVisible);   // 'f' must NOT become true
        self::assertSame(42, $row->viewCount);
        self::assertSame(3.5, $row->score);
        self::assertSame(true, $row->isPinned);
    }

    #[Test]
    public function hydrateLeavesNullableScalarNull(): void
    {
        $row = $this->hydrator->hydrate(ScalarRow::class, [
            'id'         => 'p1',
            'is_visible' => 't',
            'view_count' => '0',
            'score'      => '0',
            'is_pinned'  => null,
        ]);

        self::assertSame(true, $row->isVisible);
        self::assertSame(0, $row->viewCount);
        self::assertSame(0.0, $row->score);
        self::assertNull($row->isPinned);
    }

    #[Test]
    public function dehydrateSpellsBooleansOutForTheDriver(): void
    {
        // PDO binds execute() array parameters as strings, turning false into ''.
        $row = $this->hydrator->dehydrate(new ScalarRow('p1', false, 42, 3.5, true));

        self::assertSame('f', $row['is_visible']);
        self::assertSame('t', $row['is_pinned']);
        self::assertSame(42, $row['view_count'], 'only bools need spelling out');
        self::assertSame(3.5, $row['score']);
    }

    #[Test]
    public function booleanScalarsSurviveARoundTrip(): void
    {
        $original = new ScalarRow('p1', false, 0, 0.0, false);

        $restored = $this->hydrator->hydrate(ScalarRow::class, $this->hydrator->dehydrate($original));

        self::assertSame(false, $restored->isVisible);
        self::assertSame(false, $restored->isPinned);
    }

    #[Test]
    public function dehydrateKeepsNullableBoolNull(): void
    {
        $row = $this->hydrator->dehydrate(new ScalarRow('p1', true, 0, 0.0, null));

        self::assertNull($row['is_pinned']);
    }

    #[Test]
    public function toBindableTouchesOnlyWhatPdoWouldMangle(): void
    {
        self::assertSame('f', $this->hydrator->toBindable(false));
        self::assertSame('t', $this->hydrator->toBindable(true));
        self::assertSame(
            '2026-08-13T09:00:00+00:00',
            $this->hydrator->toBindable(
                new \DateTimeImmutable('2026-08-13 12:00:00', new \DateTimeZone('Europe/Moscow'))
            ),
        );

        // Everything PDO can already stringify is left alone.
        self::assertNull($this->hydrator->toBindable(null));
        self::assertSame(42, $this->hydrator->toBindable(42));
        self::assertSame(1.5, $this->hydrator->toBindable(1.5));
        self::assertSame('t', $this->hydrator->toBindable('t'));
    }

    #[Test]
    public function hydrateAcceptsNativeBoolFromDriver(): void
    {
        // Some drivers/configs return real bools/ints; coercion must be idempotent.
        $row = $this->hydrator->hydrate(ScalarRow::class, [
            'id'         => 'p1',
            'is_visible' => true,
            'view_count' => 7,
            'score'      => 1.25,
            'is_pinned'  => false,
        ]);

        self::assertSame(true, $row->isVisible);
        self::assertSame(7, $row->viewCount);
        self::assertSame(1.25, $row->score);
        self::assertSame(false, $row->isPinned);
    }

    #[Test]
    public function missingRequiredColumnThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/tenant_id/');

        $this->hydrator->hydrate(Document::class, ['id' => 'doc1', 'title' => 'x', 'embedding' => '[1,2,3]']);
    }
}
