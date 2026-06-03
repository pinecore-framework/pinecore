<?php

namespace Tests\Pinecore\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Orm\EntityMap;
use Tests\Pinecore\Pinecore\Fixtures\Orm\EmbeddedAddress;
use Tests\Pinecore\Pinecore\Fixtures\Orm\EntityMissingCollection;
use Tests\Pinecore\Pinecore\Fixtures\Orm\EntityWithoutId;
use Tests\Pinecore\Pinecore\Fixtures\Orm\MixedPropsEntity;
use Tests\Pinecore\Pinecore\Fixtures\Orm\SimpleUser;

final class EntityMapTest extends TestCase
{
    #[Test]
    public function topLevelEntityExposesStorageName(): void
    {
        $meta = EntityMap::of(SimpleUser::class);

        self::assertSame('simple_users', $meta->storageName);
    }

    #[Test]
    public function embeddedClassWithoutCollectionAttributeBuilds(): void
    {
        // EmbeddedAddress is not a MongoEntity and has no #[Collection] —
        // EntityMap must still build metadata for it (storageName === null).
        $meta = EntityMap::of(EmbeddedAddress::class);

        self::assertNull($meta->storageName);
        self::assertCount(2, $meta->fields);
    }

    #[Test]
    public function mongoEntityWithoutCollectionAttributeThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Collection/');

        EntityMap::of(EntityMissingCollection::class);
    }

    #[Test]
    public function propertyWithoutOrmAttributesIsSkipped(): void
    {
        $meta = EntityMap::of(MixedPropsEntity::class);

        $names = array_map(static fn ($f) => $f->property->getName(), $meta->fields);
        self::assertSame(['id', 'email'], $names);
    }

    #[Test]
    public function idFieldThrowsWhenAbsent(): void
    {
        $meta = EntityMap::of(EntityWithoutId::class);

        $this->expectException(\LogicException::class);
        $meta->idField();
    }

    #[Test]
    public function metadataIsCachedPerClass(): void
    {
        self::assertSame(EntityMap::of(SimpleUser::class), EntityMap::of(SimpleUser::class));
    }
}
