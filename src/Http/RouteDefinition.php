<?php

namespace Pinecore\Pinecore\Http;

final class RouteDefinition
{
    /**
     * @param class-string       $controller
     * @param list<class-string> $middleware
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $controller,
        public readonly array  $middleware = [],
    ) {}
}
