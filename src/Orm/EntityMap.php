<?php

namespace Pinecore\Pinecore\Orm;

use Pinecore\Pinecore\Model\MongoEntity;
use Pinecore\Pinecore\Orm\Attributes\Collection;
use Pinecore\Pinecore\Orm\Attributes\Embedded;
use Pinecore\Pinecore\Orm\Attributes\EmbeddedList;
use Pinecore\Pinecore\Orm\Attributes\Field;
use Pinecore\Pinecore\Orm\Attributes\Id;
use Pinecore\Pinecore\Orm\Attributes\Index;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;

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
        $fields  = [];
        $indexes = [];

        foreach ($ref->getProperties() as $property) {
            $fieldMeta = self::buildFieldMetadata($property, $constructorParams, $class);
            if ($fieldMeta !== null) {
                $fields[] = $fieldMeta;
            }

            $indexAttrs = $property->getAttributes(Index::class);
            if (empty($indexAttrs)) {
                continue;
            }
            if ($fieldMeta === null || $fieldMeta->isEmbedded || $fieldMeta->isEmbeddedList) {
                throw new \LogicException(
                    "#[Index] on {$class}::\${$property->getName()} requires #[Field] or #[Id] on the same property."
                );
            }
            foreach ($indexAttrs as $indexAttr) {
                /** @var Index $idx */
                $idx       = $indexAttr->newInstance();
                $indexes[] = new IndexMetadata(
                    field: $fieldMeta->field,
                    direction: $idx->direction,
                    unique: $idx->unique,
                    sparse: $idx->sparse,
                    name: $idx->name,
                    expireAfterSeconds: $idx->expireAfterSeconds,
                );
            }
        }

        return new EntityMetadata($collectionName, $fields, $indexes);
    }

    /**
     * @param array<string, ReflectionParameter> $constructorParams
     */
    private static function buildFieldMetadata(
        ReflectionProperty $property,
        array $constructorParams,
        string $entityClass,
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
            $embeddedClass = self::resolveEmbeddedClass($property, $entityClass);
            return new FieldMetadata(
                property: $property,
                field: $property->getName(),
                isId: false,
                type: $embeddedClass,
                isEmbedded: true,
                isEmbeddedList: false,
                embeddedClass: $embeddedClass,
                isNullable: $isNullable,
                hasConstructorDefault: $hasDefault,
            );
        }

        /** @var EmbeddedList $embeddedList */
        $embeddedList = $embeddedListAttrs[0]->newInstance();
        self::validateEmbeddedListProperty($property, $embeddedList->class, $entityClass);
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

    /**
     * Validates that the property bearing #[Embedded] is typed with a single
     * named, existing class (optionally nullable). Returns the FQCN.
     */
    private static function resolveEmbeddedClass(ReflectionProperty $property, string $entityClass): string
    {
        $type = $property->getType();
        $where = "{$entityClass}::\${$property->getName()}";

        if ($type === null) {
            throw new \LogicException("#[Embedded] on {$where} requires a typed property");
        }
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            throw new \LogicException(
                "#[Embedded] on {$where} requires a single named class type, got compound type \"{$type}\""
            );
        }
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new \LogicException(
                "#[Embedded] on {$where} requires a single named class type, got \"{$type}\""
            );
        }

        $class = $type->getName();
        if (!class_exists($class)) {
            throw new \LogicException(
                "#[Embedded] class \"{$class}\" on {$where} does not exist"
            );
        }
        return $class;
    }

    /**
     * Validates that the property bearing #[EmbeddedList] is typed as array
     * and that the configured item class actually exists.
     */
    private static function validateEmbeddedListProperty(
        ReflectionProperty $property,
        string $itemClass,
        string $entityClass,
    ): void {
        $where = "{$entityClass}::\${$property->getName()}";

        if (!class_exists($itemClass)) {
            throw new \LogicException(
                "#[EmbeddedList] class \"{$itemClass}\" on {$where} does not exist"
            );
        }

        $type = $property->getType();
        if ($type === null) {
            throw new \LogicException("#[EmbeddedList] on {$where} requires array property type");
        }
        if (!$type instanceof ReflectionNamedType || $type->getName() !== 'array') {
            throw new \LogicException(
                "#[EmbeddedList] on {$where} requires array property type, got \"{$type}\""
            );
        }
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
