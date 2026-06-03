<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;
use Pinecore\Pinecore\Orm\SqlHydrator;
use Tests\Pinecore\Pinecore\Fixtures\Orm\Document;

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
    public function missingRequiredColumnThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/tenant_id/');

        $this->hydrator->hydrate(Document::class, ['id' => 'doc1', 'title' => 'x', 'embedding' => '[1,2,3]']);
    }
}
