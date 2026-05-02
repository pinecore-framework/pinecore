<?php

namespace Pinecore\Pinecore\Console;

final class OptionDefinition
{
    /**
     * @param mixed $default  false → boolean flag; null or string → value option
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly mixed  $default = false,
    ) {}
}
