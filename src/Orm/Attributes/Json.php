<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

/**
 * Marks a property as a JSON/JSONB column. The PHP value (array or value object)
 * is encoded on write and decoded on read by SqlHydrator.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Json
{
    public function __construct(
        public readonly ?string $name = null,
    ) {}
}
