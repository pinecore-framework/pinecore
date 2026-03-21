<?php

namespace Pronchev\Pinecore\Orm\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Field
{
    public function __construct(
        public readonly ?string $name = null,
    ) {}
}
