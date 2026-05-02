<?php

namespace Tests\Pronchev\Pinecore\Orm;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pronchev\Pinecore\Orm\EntityMap;
use Tests\Pronchev\Pinecore\Fixtures\Orm\EmbeddedAddress;
use Tests\Pronchev\Pinecore\Fixtures\Orm\EmbeddedListOnPrimitive;
use Tests\Pronchev\Pinecore\Fixtures\Orm\EmbeddedListWithMissingClass;
use Tests\Pronchev\Pinecore\Fixtures\Orm\EmbeddedOnPrimitive;
use Tests\Pronchev\Pinecore\Fixtures\Orm\EmbeddedOnUnion;
use Tests\Pronchev\Pinecore\Fixtures\Orm\EmbeddedOnUntypedProperty;
use Tests\Pronchev\Pinecore\Fixtures\Orm\UserWithNullableEmbedded;
use Tests\Pronchev\Pinecore\Fixtures\Orm\UserWithNullableEmbeddedList;

final class EntityMapValidationTest extends TestCase
{
    #[Test]
    public function embeddedOnPrimitiveTypeThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/#\[Embedded\].+requires a single named class type.+"string"/');

        EntityMap::of(EmbeddedOnPrimitive::class);
    }

    #[Test]
    public function embeddedOnUntypedPropertyThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/#\[Embedded\].+requires a typed property/');

        EntityMap::of(EmbeddedOnUntypedProperty::class);
    }

    #[Test]
    public function embeddedOnUnionTypeThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/#\[Embedded\].+compound type/');

        EntityMap::of(EmbeddedOnUnion::class);
    }

    #[Test]
    public function nullableEmbeddedClassResolves(): void
    {
        $meta = EntityMap::of(UserWithNullableEmbedded::class);

        $address = self::fieldByName($meta, 'address');
        self::assertTrue($address->isEmbedded);
        self::assertSame(EmbeddedAddress::class, $address->embeddedClass);
        self::assertTrue($address->isNullable);
    }

    #[Test]
    public function embeddedListWithMissingClassThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/#\[EmbeddedList\] class "App\\\\NonExistentEmbeddedItem".+does not exist/');

        EntityMap::of(EmbeddedListWithMissingClass::class);
    }

    #[Test]
    public function embeddedListOnPrimitivePropertyThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/#\[EmbeddedList\].+requires array property type.+"string"/');

        EntityMap::of(EmbeddedListOnPrimitive::class);
    }

    #[Test]
    public function nullableArrayPropertyIsAcceptedForEmbeddedList(): void
    {
        $meta = EntityMap::of(UserWithNullableEmbeddedList::class);

        $addresses = self::fieldByName($meta, 'addresses');
        self::assertTrue($addresses->isEmbeddedList);
        self::assertSame(EmbeddedAddress::class, $addresses->embeddedClass);
        self::assertTrue($addresses->isNullable);
    }

    private static function fieldByName(\Pronchev\Pinecore\Orm\EntityMetadata $meta, string $name): \Pronchev\Pinecore\Orm\FieldMetadata
    {
        foreach ($meta->fields as $field) {
            if ($field->property->getName() === $name) {
                return $field;
            }
        }
        self::fail("No field named {$name} in metadata");
    }
}
