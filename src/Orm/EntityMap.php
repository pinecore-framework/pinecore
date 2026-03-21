<?php

namespace Pronchev\Pinecore\Orm;

use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\Embedded;
use Pronchev\Pinecore\Orm\Attributes\EmbeddedList;
use Pronchev\Pinecore\Orm\Attributes\Field;
use Pronchev\Pinecore\Orm\Attributes\Id;
use ReflectionClass;
use ReflectionProperty;

final class EntityMap
{
    /** @var array<string, EntityMetadata> */
    private static array $cache = [];

    public static function of(string $class): EntityMetadata
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        self::$cache[$class] = self::build($class);

        return self::$cache[$class];
    }

    private static function build(string $class): EntityMetadata
    {
        $ref = new ReflectionClass($class);

        $collectionAttrs = $ref->getAttributes(Collection::class);
        if (empty($collectionAttrs)) {
            throw new \LogicException("Class {$class} is missing #[Collection] attribute.");
        }

        /** @var Collection $collection */
        $collection = $collectionAttrs[0]->newInstance();
        $fields = [];

        foreach ($ref->getProperties() as $property) {
            $fieldMeta = self::buildFieldMetadata($property);
            if ($fieldMeta !== null) {
                $fields[] = $fieldMeta;
            }
        }

        return new EntityMetadata($collection->name, $fields);
    }

    private static function buildFieldMetadata(ReflectionProperty $property): ?FieldMetadata
    {
        $idAttrs = $property->getAttributes(Id::class);
        $fieldAttrs = $property->getAttributes(Field::class);
        $embeddedAttrs = $property->getAttributes(Embedded::class);
        $embeddedListAttrs = $property->getAttributes(EmbeddedList::class);

        if (!empty($idAttrs)) {
            return new FieldMetadata(
                property: $property,
                field: $property->getName(),
                isId: true,
                type: self::typeName($property),
                isEmbedded: false,
                isEmbeddedList: false,
                embeddedClass: null,
            );
        }

        if (!empty($fieldAttrs)) {
            /** @var Field $field */
            $field = $fieldAttrs[0]->newInstance();
            return new FieldMetadata(
                property: $property,
                field: $field->name ?? $property->getName(),
                isId: false,
                type: self::typeName($property),
                isEmbedded: false,
                isEmbeddedList: false,
                embeddedClass: null,
            );
        }

        if (!empty($embeddedAttrs)) {
            return new FieldMetadata(
                property: $property,
                field: $property->getName(),
                isId: false,
                type: self::typeName($property),
                isEmbedded: true,
                isEmbeddedList: false,
                embeddedClass: self::typeName($property),
            );
        }

        if (!empty($embeddedListAttrs)) {
            /** @var EmbeddedList $embeddedList */
            $embeddedList = $embeddedListAttrs[0]->newInstance();
            return new FieldMetadata(
                property: $property,
                field: $property->getName(),
                isId: false,
                type: 'array',
                isEmbedded: false,
                isEmbeddedList: true,
                embeddedClass: $embeddedList->class,
            );
        }

        return null;
    }

    private static function typeName(ReflectionProperty $property): string
    {
        $type = $property->getType();
        if ($type === null) {
            return 'mixed';
        }
        return (string) $type;
    }
}
