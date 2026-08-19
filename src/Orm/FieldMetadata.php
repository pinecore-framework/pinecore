<?php

namespace Pinecore\Pinecore\Orm;

use ReflectionProperty;

final class FieldMetadata
{
    public function __construct(
        public readonly ReflectionProperty $property,
        public readonly string $field,
        public readonly bool $isId,
        public readonly string $type,
        public readonly bool $isEmbedded,
        public readonly bool $isEmbeddedList,
        public readonly ?string $embeddedClass,
        public readonly bool $isNullable,
        public readonly bool $hasConstructorDefault,
        public readonly bool $isJson = false,
        public readonly bool $isVector = false,
        public readonly bool $isPgArray = false,
        public readonly int $vectorDim = 0,
        public readonly string $vectorMetric = 'cosine',
        /** SQL expression wrapping the column on SELECT; `%s` = quoted column name. */
        public readonly ?string $readExpr = null,
        public readonly bool $isGeometry = false,
        public readonly int $geometrySrid = 0,
        public readonly ?string $geometryType = null,
        /** Whether pg:ensure-indexes should emit a GiST index for this geometry column. */
        public readonly bool $geometryIndex = false,
    ) {}

    /**
     * Whether the field maps to a real SQL column. #[Embedded]/#[EmbeddedList]
     * are Mongo-only shapes and have no column of their own.
     */
    public function isSqlColumn(): bool
    {
        return !$this->isEmbedded && !$this->isEmbeddedList;
    }
}
