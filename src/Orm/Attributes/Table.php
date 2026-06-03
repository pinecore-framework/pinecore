<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

/**
 * Declares the SQL table backing an entity. SQL analog of #[Collection].
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    public function __construct(
        public readonly string $name,
    ) {}
}
