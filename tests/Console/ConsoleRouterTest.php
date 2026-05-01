<?php

namespace Tests\Pronchev\Pinecore\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pronchev\Pinecore\Console\ConsoleDefinition;
use Pronchev\Pinecore\Console\ConsoleRouter;

final class ConsoleRouterTest extends TestCase
{
    #[Test]
    public function matchesStaticSignature(): void
    {
        $router = new ConsoleRouter([
            new ConsoleDefinition('mongo:ensure-indexes', 'Handler', 'Ensure indexes'),
        ]);

        $match = $router->match('mongo:ensure-indexes');

        self::assertTrue($match->found);
        self::assertSame('Handler', $match->definition->handler);
    }

    #[Test]
    public function capturesNamedParameter(): void
    {
        $router = new ConsoleRouter([
            new ConsoleDefinition('users:create {name}', 'Handler', 'Create user'),
        ]);

        $match = $router->match('users:create admin');

        self::assertTrue($match->found);
        self::assertSame(['name' => 'admin'], $match->pathParams);
    }

    #[Test]
    public function literalDotInSignatureIsNotRegexWildcard(): void
    {
        $router = new ConsoleRouter([
            new ConsoleDefinition('schema.v1:apply', 'Handler', 'Apply schema'),
        ]);

        self::assertFalse($router->match('schemaXv1:apply')->found);
        self::assertTrue($router->match('schema.v1:apply')->found);
    }

    #[Test]
    public function unknownCommandReturnsNotFound(): void
    {
        $router = new ConsoleRouter([
            new ConsoleDefinition('users:create {email}', 'Handler', 'Create user'),
        ]);

        self::assertFalse($router->match('users:delete admin')->found);
    }
}
