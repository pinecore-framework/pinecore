<?php

namespace Pronchev\Pinecore\Http;

final class Router
{
    /**
     * @var list<array{definition: RouteDefinition, regex: string, params: list<string>}>
     */
    private array $compiled = [];

    /** @param list<RouteDefinition> $routes */
    public function __construct(array $routes)
    {
        foreach ($routes as $route) {
            $this->compiled[] = $this->compile($route);
        }
    }

    public function match(string $method, string $path): RouteMatch
    {
        $method          = strtoupper($method);
        $allowedMethods  = [];

        foreach ($this->compiled as $entry) {
            if (!preg_match($entry['regex'], $path, $m)) {
                continue;
            }

            // Path matched — collect the allowed method regardless
            $allowedMethods[] = $entry['definition']->method;

            if ($entry['definition']->method !== $method) {
                continue;
            }

            $params = [];
            foreach ($entry['params'] as $name) {
                $params[$name] = $m[$name];
            }

            return RouteMatch::found($entry['definition'], $params);
        }

        if ($allowedMethods !== []) {
            return RouteMatch::methodNotAllowed(array_unique($allowedMethods));
        }

        return RouteMatch::notFound();
    }

    /** @return array{definition: RouteDefinition, regex: string, params: list<string>} */
    private function compile(RouteDefinition $route): array
    {
        $params  = [];
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $route->path,
        );

        return [
            'definition' => $route,
            'regex'      => '#^' . $pattern . '$#',
            'params'     => $params,
        ];
    }
}
