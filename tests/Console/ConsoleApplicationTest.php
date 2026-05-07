<?php

namespace Tests\Pinecore\Pinecore\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Console\ConsoleApplication;
use Pinecore\Pinecore\Console\ConsoleDefinition;
use Pinecore\Pinecore\Console\ConsoleInput;
use Pinecore\Pinecore\Console\ConsoleOutput;
use Pinecore\Pinecore\Console\ConsoleRouter;
use Pinecore\Pinecore\Console\OptionDefinition;
use Psr\Container\ContainerInterface;

final class ConsoleApplicationTest extends TestCase
{
    #[Test]
    public function helpOnEmptyRouterDoesNotCrash(): void
    {
        $app = new ConsoleApplication(
            $this->createMock(ContainerInterface::class),
            new ConsoleRouter([]),
        );

        ob_start();
        $exit = $app->run(['bin/console']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('No commands registered', $output);
    }

    #[Test]
    public function positionalArgumentMayFollowOption(): void
    {
        $captured = null;
        $handler  = new class($captured) {
            public function __construct(private mixed &$captured) {}
            public function __invoke(ConsoleInput $input, ConsoleOutput $output): void
            {
                $this->captured = $input;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with($handler::class)->willReturn($handler);

        $router = new ConsoleRouter([
            new ConsoleDefinition(
                'users:create {role}',
                $handler::class,
                'Create user',
                [new OptionDefinition('dry-run', 'Do not persist', false)],
            ),
        ]);

        $app = new ConsoleApplication($container, $router);

        $exit = $app->run(['bin/console', 'users:create', '--dry-run', 'admin']);

        self::assertSame(0, $exit);
        self::assertInstanceOf(ConsoleInput::class, $captured);
        self::assertSame(['role' => 'admin'], $captured->pathParams);
        self::assertTrue($captured->options['dry-run']);
    }
}
