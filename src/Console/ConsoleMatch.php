<?php

namespace Pronchev\Pinecore\Console;

final class ConsoleMatch
{
    private function __construct(
        public readonly bool               $found,
        public readonly ?ConsoleDefinition $definition,
        public readonly array              $pathParams,
    ) {}

    public static function found(ConsoleDefinition $definition, array $pathParams): self
    {
        return new self(true, $definition, $pathParams);
    }

    public static function notFound(): self
    {
        return new self(false, null, []);
    }
}
