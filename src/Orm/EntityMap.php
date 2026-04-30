<?php

namespace Pronchev\Pinecore\Orm;

use Pronchev\Pinecore\Model\MongoEntity;
use Pronchev\Pinecore\Orm\Attributes\Collection;
use Pronchev\Pinecore\Orm\Attributes\Embedded;
use Pronchev\Pinecore\Orm\Attributes\EmbeddedList;
use Pronchev\Pinecore\Orm\Attributes\Field;
use Pronchev\Pinecore\Orm\Attributes\Id;
use ReflectionClass;
use ReflectionParameter;
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
        $isMongoEntity   = is_a($class, MongoEntity::class, true);

        if ($isMongoEntity && empty($collectionAttrs)) {
            throw new \LogicException("Class {$class} is missing #[Collection] attribute.");
        }

        $collectionName = empty($collectionAttrs)
            ? null
            : $collectionAttrs[0]->newInstance()->name;

        $constructorParams = self::constructorParamsByName($ref);
        $fields = [];

        foreach ($ref->getProperties() as $property) {
            $fieldMeta = self::buildFieldMetadata($property, $constructorParams);
            if ($fieldMeta !== null) {
                $fields[] = $fieldMeta;
            }
        }

        return new EntityMetadata($collectionName, $fields);
    }

    /**
     * @param array<string, ReflectionParameter> $constructorParams
     */
    private static function buildFieldMetadata(
        ReflectionProperty $property,
        array $constructorParams,
    ): ?FieldMetadata {
        $idAttrs = $property->getAttributes(Id::class);
        $fieldAttrs = $property->getAttributes(Field::class);
        $embeddedAttrs = $property->getAttributes(Embedded::class);
        $embeddedListAttrs = $property->getAttributes(EmbeddedList::class);

        if (empty($idAttrs) && empty($fieldAttrs) && empty($embeddedAttrs) && empty($embeddedListAttrs)) {
            return null;
        }

        $param      = $constructorParams[$property->getName()] ?? null;
        $isNullable = self::resolveNullable($property, $param);
        $hasDefault = $param !== null && $param->isDefaultValueAvailable();

        if (!empty($idAttrs)) {
            return new FieldMetadata(
                property: $property,
                field: $property->getName(),
                isId: true,
                type: self::typeName($property),
                isEmbedded: false,
                isEmbeddedList: false,
                embeddedClass: null,
                isNullable: $isNullable,
                hasConstructorDefault: $hasDefault,
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
                isNullable: $isNullable,
                hasConstructorDefault: $hasDefault,
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
                isNullable: $isNullable,
                hasConstructorDefault: $hasDefault,
            );
        }

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
            isNullable: $isNullable,
            hasConstructorDefault: $hasDefault,
        );
    }

    /** @return array<string, ReflectionParameter> */
    private static function constructorParamsByName(ReflectionClass $ref): array
    {
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return [];
        }

        $map = [];
        foreach ($ctor->getParameters() as $param) {
            $map[$param->getName()] = $param;
        }
        return $map;
    }

    private static function resolveNullable(ReflectionProperty $property, ?ReflectionParameter $param): bool
    {
        $type = $property->getType();
        if ($type !== null && $type->allowsNull()) {
            return true;
        }
        if ($param !== null) {
            $paramType = $param->getType();
            if ($paramType !== null && $paramType->allowsNull()) {
                return true;
            }
        }
        return false;
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
