<?php

namespace Pronchev\Pinecore\Http;

final class RouteMatch
{
    /** @param list<string> $allowedMethods populated on 405 */
    private function __construct(
        public readonly bool             $found,
        public readonly bool             $methodNotAllowed,
        public readonly ?RouteDefinition $definition,
        public readonly array            $pathParams,
        public readonly array            $allowedMethods = [],
    ) {}

    public static function found(RouteDefinition $definition, array $pathParams): self
    {
        return new self(true, false, $definition, $pathParams);
    }

    /** @param list<string> $allowedMethods */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(false, true, null, [], $allowedMethods);
    }

    public static function notFound(): self
    {
        return new self(false, false, null, []);
    }
}
