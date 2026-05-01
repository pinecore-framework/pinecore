<?php

namespace Tests\Pronchev\Pinecore\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pronchev\Pinecore\Http\RouteDefinition;
use Pronchev\Pinecore\Http\Router;

final class RouterTest extends TestCase
{
    #[Test]
    public function matchesStaticPath(): void
    {
        $router = new Router([
            new RouteDefinition('GET', '/health', 'Controller'),
        ]);

        $match = $router->match('GET', '/health');

        self::assertTrue($match->found);
        self::assertSame('Controller', $match->definition->controller);
        self::assertSame([], $match->pathParams);
    }

    #[Test]
    public function capturesNamedParameter(): void
    {
        $router = new Router([
            new RouteDefinition('GET', '/users/{id}', 'Controller'),
        ]);

        $match = $router->match('GET', '/users/42');

        self::assertTrue($match->found);
        self::assertSame(['id' => '42'], $match->pathParams);
    }

    #[Test]
    public function literalDotIsNotTreatedAsRegexWildcard(): void
    {
        $router = new Router([
            new RouteDefinition('GET', '/api/v1.0/users/{id}', 'Controller'),
        ]);

        // Without preg_quote-ing the literals, the dot would match any character
        // and a path like /api/v1X0/users/42 would falsely match.
        self::assertFalse($router->match('GET', '/api/v1X0/users/42')->found);
        self::assertTrue($router->match('GET', '/api/v1.0/users/42')->found);
    }

    #[Test]
    public function literalRegexMetacharsAreEscaped(): void
    {
        $router = new Router([
            new RouteDefinition('GET', '/files/a+b/{id}', 'Controller'),
        ]);

        // '+' as regex would mean "one or more 'a'", matching '/files/aaa/42'.
        self::assertFalse($router->match('GET', '/files/aaa/42')->found);

        $match = $router->match('GET', '/files/a+b/42');
        self::assertTrue($match->found);
        self::assertSame(['id' => '42'], $match->pathParams);
    }

    #[Test]
    public function unknownPathReturnsNotFound(): void
    {
        $router = new Router([
            new RouteDefinition('GET', '/users/{id}', 'Controller'),
        ]);

        $match = $router->match('GET', '/nope');

        self::assertFalse($match->found);
        self::assertFalse($match->methodNotAllowed);
    }

    #[Test]
    public function methodMismatchReturnsAllowedMethods(): void
    {
        $router = new Router([
            new RouteDefinition('GET',  '/users/{id}', 'GetController'),
            new RouteDefinition('POST', '/users/{id}', 'PostController'),
        ]);

        $match = $router->match('DELETE', '/users/42');

        self::assertFalse($match->found);
        self::assertTrue($match->methodNotAllowed);
        self::assertEqualsCanonicalizing(['GET', 'POST'], $match->allowedMethods);
    }

    #[Test]
    public function pathParameterStopsAtSlash(): void
    {
        $router = new Router([
            new RouteDefinition('GET', '/users/{id}', 'Controller'),
        ]);

        // {id} captures [^/]+, so an extra segment must not match.
        self::assertFalse($router->match('GET', '/users/42/posts')->found);
    }
}
