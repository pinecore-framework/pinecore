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
        public readonly int $vectorDim = 0,
        public readonly string $vectorMetric = 'cosine',
    ) {}
}
