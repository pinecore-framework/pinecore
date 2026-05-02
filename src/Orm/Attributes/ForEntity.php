<?php

namespace Pinecore\Pinecore\Orm\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class ForEntity
{
    public function __construct(public readonly string $class) {}
}
