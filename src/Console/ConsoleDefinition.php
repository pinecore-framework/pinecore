<?php

namespace Pinecore\Pinecore\Console;

final class ConsoleDefinition
{
    /**
     * @param class-string           $handler
     * @param list<OptionDefinition> $options
     */
    public function __construct(
        public readonly string $signature,
        public readonly string $handler,
        public readonly string $description,
        public readonly array  $options = [],
    ) {}
}
