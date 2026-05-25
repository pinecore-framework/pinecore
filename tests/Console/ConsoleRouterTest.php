<?php

namespace Tests\Pinecore\Pinecore\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Console\ConsoleDefinition;
use Pinecore\Pinecore\Console\ConsoleRouter;

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
    public function argumentValueMayContainDot(): void
    {
        $router = new ConsoleRouter([
            new ConsoleDefinition('runtime:build {key}', 'Handler', 'Build runtime'),
        ]);

        $match = $router->match('runtime:build php-8.3');

        self::assertTrue($match->found);
        self::assertSame(['key' => 'php-8.3'], $match->pathParams);
    }

    #[Test]
    public function argumentValueMayContainColon(): void
    {
        $router = new ConsoleRouter([
            new ConsoleDefinition('runtime:build {key}', 'Handler', 'Build runtime'),
        ]);

        $match = $router->match('runtime:build cache:clear');

        self::assertTrue($match->found);
        self::assertSame(['key' => 'cache:clear'], $match->pathParams);
    }

    #[Test]
    public function optionalArgumentIsCapturedWhenPresent(): void
    {
        $router = new ConsoleRouter([
            new ConsoleDefinition('runtime:build {key?}', 'Handler', 'Build runtime'),
        ]);

        $match = $router->match('runtime:build php-8.3');

        self::assertTrue($match->found);
        self::assertSame(['key' => 'php-8.3'], $match->pathParams);
    }

    #[Test]
    public function optionalArgumentMayBeOmitted(): void
    {
        $router = new ConsoleRouter([
            new ConsoleDefinition('runtime:build {key?}', 'Handler', 'Build runtime'),
        ]);

        $match = $router->match('runtime:build');

        self::assertTrue($match->found);
        self::assertSame(['key' => ''], $match->pathParams);
    }

    #[Test]
    public function requiredArgumentIsStillRequired(): void
    {
        $router = new ConsoleRouter([
            new ConsoleDefinition('runtime:build {key}', 'Handler', 'Build runtime'),
        ]);

        self::assertFalse($router->match('runtime:build')->found);
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
