<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Collection
{
    public function __construct(
        public readonly string $name,
    ) {}
}
