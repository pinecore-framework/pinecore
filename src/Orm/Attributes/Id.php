<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

/**
 * Marks the entity's primary key.
 *
 * @param ?string $name Storage name of the key (SQL column / Mongo document key);
 *                      defaults to the property name — same rule as #[Field]::$name.
 *                      Lets `links.object_id` back a camelCase `$objectId` property.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Id
{
    public function __construct(
        public readonly ?string $name = null,
    ) {}
}
