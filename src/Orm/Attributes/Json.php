<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

/**
 * Marks a property as a JSON/JSONB column. The PHP value (array or value object)
 * is encoded on write and decoded on read by SqlHydrator.
 *
 * `asObject` declares that the column is semantically a map: an empty PHP array
 * is encoded as `{}` instead of `[]`. PHP cannot express "empty map" as a value
 * (an empty array is type-ambiguous), so the disambiguation lives here, in the
 * declaration. Only the top-level value is affected — nested empty arrays stay
 * ambiguous, since nothing declares their shape. Not JSON_FORCE_OBJECT: non-empty
 * values (including nested lists) encode exactly as before.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Json
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly bool $asObject = false,
    ) {}
}
