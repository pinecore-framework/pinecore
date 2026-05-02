<?php

namespace Pinecore\Pinecore\Orm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class Index
{
    public function __construct(
        public readonly int     $direction = 1,
        public readonly bool    $unique = false,
        public readonly bool    $sparse = false,
        public readonly ?string $name = null,
        public readonly ?int    $expireAfterSeconds = null,
    ) {}
}
