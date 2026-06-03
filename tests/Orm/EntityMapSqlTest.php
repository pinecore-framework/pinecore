<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\EntityMap;
use Pinecore\Pinecore\Orm\FieldMetadata;
use Tests\Pinecore\Pinecore\Fixtures\Orm\Document;
use Tests\Pinecore\Pinecore\Fixtures\Orm\SqlEntityMissingTable;

final class EntityMapSqlTest extends TestCase
{
    #[Test]
    public function sqlEntityExposesTableAsStorageName(): void
    {
        self::assertSame('documents', EntityMap::of(Document::class)->storageName);
    }

    #[Test]
    public function vectorFieldCarriesDimAndMetric(): void
    {
        $embedding = $this->field(Document::class, 'embedding');

        self::assertTrue($embedding->isVector);
        self::assertFalse($embedding->isJson);
        self::assertSame(3, $embedding->vectorDim);
        self::assertSame('cosine', $embedding->vectorMetric);
    }

    #[Test]
    public function jsonFieldIsFlagged(): void
    {
        self::assertTrue($this->field(Document::class, 'metadata')->isJson);
    }

    #[Test]
    public function fieldNameOverrideIsHonoured(): void
    {
        self::assertSame('tenant_id', $this->field(Document::class, 'tenantId')->field);
    }

    #[Test]
    public function sqlEntityWithoutTableThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Table/');

        EntityMap::of(SqlEntityMissingTable::class);
    }

    private function field(string $class, string $property): FieldMetadata
    {
        foreach (EntityMap::of($class)->fields as $field) {
            if ($field->property->getName() === $property) {
                return $field;
            }
        }
        self::fail("No field metadata for {$class}::\${$property}");
    }
}
