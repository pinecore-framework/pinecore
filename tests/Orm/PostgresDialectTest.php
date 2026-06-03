<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\Sql\PostgresDialect;

final class PostgresDialectTest extends TestCase
{
    private PostgresDialect $dialect;

    protected function setUp(): void
    {
        $this->dialect = new PostgresDialect();
    }

    #[Test]
    public function quotesAndEscapesIdentifiers(): void
    {
        self::assertSame('"users"', $this->dialect->quoteIdentifier('users'));
        self::assertSame('"we""ird"', $this->dialect->quoteIdentifier('we"ird'));
    }

    #[Test]
    public function buildsUpsertKeyedOnId(): void
    {
        $sql = $this->dialect->upsert('documents', ['id', 'title', 'embedding'], 'id');

        self::assertSame(
            'INSERT INTO "documents" ("id", "title", "embedding") '
            . 'VALUES (:id, :title, :embedding) '
            . 'ON CONFLICT ("id") DO UPDATE SET "title" = EXCLUDED."title", "embedding" = EXCLUDED."embedding"',
            $sql,
        );
    }

    #[Test]
    public function rendersVectorLiteral(): void
    {
        self::assertSame('[0.1,0.2,0.3]', $this->dialect->vectorLiteral([0.1, 0.2, 0.3]));
        self::assertSame('[]', $this->dialect->vectorLiteral([]));
    }

    #[Test]
    public function vectorDistancePicksOperatorPerMetric(): void
    {
        self::assertSame('"embedding" <=> :vec', $this->dialect->vectorDistance('embedding', ':vec', 'cosine'));
        self::assertSame('"embedding" <-> :vec', $this->dialect->vectorDistance('embedding', ':vec', 'l2'));
        self::assertSame('"embedding" <#> :vec', $this->dialect->vectorDistance('embedding', ':vec', 'ip'));
    }

    #[Test]
    public function unknownMetricThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dialect->vectorDistance('embedding', ':vec', 'hamming');
    }
}
