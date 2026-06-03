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

    #[Test]
    public function classifiesConnectionLossBySqlState(): void
    {
        self::assertTrue($this->dialect->isConnectionLost($this->pdoException('boom', '08006')));
        self::assertTrue($this->dialect->isConnectionLost($this->pdoException('boom', '08003')));
        self::assertTrue($this->dialect->isConnectionLost($this->pdoException('boom', '57P01')));
    }

    #[Test]
    public function classifiesConnectionLossByMessageWhenSqlStateAbsent(): void
    {
        self::assertTrue($this->dialect->isConnectionLost(
            $this->pdoException('SQLSTATE[HY000]: server closed the connection unexpectedly')
        ));
    }

    #[Test]
    public function queryErrorsAreNotConnectionLoss(): void
    {
        self::assertFalse($this->dialect->isConnectionLost(
            $this->pdoException('duplicate key value violates unique constraint', '23505')
        ));
    }

    private function pdoException(string $message, int|string $code = 0): \PDOException
    {
        $e = new \PDOException($message);
        if ($code !== 0) {
            $property = new \ReflectionProperty(\Exception::class, 'code');
            $property->setValue($e, $code);   // SQLSTATE is a string at the driver level
        }
        return $e;
    }
}
