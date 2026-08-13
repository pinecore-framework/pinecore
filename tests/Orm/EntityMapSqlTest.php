<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\EntityMap;
use Pinecore\Pinecore\Orm\FieldMetadata;
use Tests\Pinecore\Pinecore\Fixtures\Orm\Document;
use Tests\Pinecore\Pinecore\Fixtures\Orm\GeoPlace;
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
    public function geometryFieldCarriesReadExpressionAndIndexIntent(): void
    {
        $geom = $this->field(GeoPlace::class, 'geom');

        self::assertTrue($geom->isGeometry);
        self::assertTrue($geom->geometryIndex);
        self::assertSame(4326, $geom->geometrySrid);
        self::assertSame('Point', $geom->geometryType);
        self::assertSame('ST_AsEWKT(%s)', $geom->readExpr);
        self::assertFalse($geom->isVector, 'geometry is a plain column, not a vector');
    }

    #[Test]
    public function plainFieldHasNoReadExpressionByDefault(): void
    {
        self::assertNull($this->field(GeoPlace::class, 'source')->readExpr);
    }

    #[Test]
    public function indexMethodIsCarriedThrough(): void
    {
        $bbox = null;
        foreach (EntityMap::of(GeoPlace::class)->indexes as $index) {
            if ($index->field === 'bbox') {
                $bbox = $index;
            }
        }

        self::assertNotNull($bbox);
        self::assertSame('gist', $bbox->using);
    }

    #[Test]
    public function compositeIndexesResolvePropertyNamesToColumns(): void
    {
        $composites = EntityMap::of(GeoPlace::class)->compositeIndexes;

        self::assertCount(2, $composites);
        // 'externalId' is a property name and must land as its column, 'source' is already one.
        self::assertSame(['source', 'external_id'], $composites[0]->columns);
        self::assertTrue($composites[0]->unique);
        self::assertNull($composites[0]->name);

        // Names that match no property pass through verbatim — raw columns stay usable.
        self::assertSame(['context_hash', 'place_id'], $composites[1]->columns);
        self::assertFalse($composites[1]->unique);
        self::assertSame('idx_ctx', $composites[1]->name);
    }

    #[Test]
    public function entityWithoutCompositeIndexesHasNone(): void
    {
        self::assertSame([], EntityMap::of(Document::class)->compositeIndexes);
    }

    #[Test]
    public function sqlFieldsSkipMongoOnlyShapes(): void
    {
        $columns = array_map(
            static fn(FieldMetadata $f): string => $f->field,
            EntityMap::of(Document::class)->sqlFields(),
        );

        self::assertSame(['id', 'tenant_id', 'title', 'embedding', 'status', 'metadata'], $columns);
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
