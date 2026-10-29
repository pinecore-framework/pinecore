<?php

namespace Tests\Pinecore\Pinecore\Console;

use League\CLImate\CLImate;
use League\CLImate\Util\Writer\Buffer;
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
    public function helpOnEmptyRouterShowsHeaderAndHint(): void
    {
        [$climate, $buffer] = self::bufferedClimate();

        $app = new ConsoleApplication(
            $this->createMock(ContainerInterface::class),
            new ConsoleRouter([]),
            $climate,
        );

        $exit = $app->run(['bin/console']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Pinecore Console', $buffer->get());
        self::assertStringContainsString('No commands registered', $buffer->get());
    }

    #[Test]
    public function helpListsCommandsGroupedByNamespace(): void
    {
        [$climate, $buffer] = self::bufferedClimate();

        $router = new ConsoleRouter([
            new ConsoleDefinition('mongo:ensure-indexes', 'Foo', 'Ensure MongoDB indexes'),
            new ConsoleDefinition('users:create {role}', 'Bar', 'Create a user'),
            new ConsoleDefinition('users:delete {id}',   'Baz', 'Delete a user'),
        ]);

        $app = new ConsoleApplication(
            $this->createMock(ContainerInterface::class),
            $router,
            $climate,
        );

        $app->run(['bin/console', 'help']);

        $out = $buffer->get();
        // Group headers
        self::assertMatchesRegularExpression('/\bmongo\b/', $out);
        self::assertMatchesRegularExpression('/\busers\b/', $out);
        // Commands and descriptions
        self::assertStringContainsString('mongo:ensure-indexes', $out);
        self::assertStringContainsString('Ensure MongoDB indexes', $out);
        self::assertStringContainsString('users:create {role}', $out);
        self::assertStringContainsString('Create a user', $out);
    }

    #[Test]
    public function helpForSingleCommandShowsArgumentsAndOptions(): void
    {
        [$climate, $buffer] = self::bufferedClimate();

        $router = new ConsoleRouter([
            new ConsoleDefinition(
                'users:create {role}',
                'Foo',
                'Create a user',
                [
                    new OptionDefinition('email',   'User email',     null),
                    new OptionDefinition('dry-run', 'Do not persist', false),
                ],
            ),
        ]);

        $app = new ConsoleApplication(
            $this->createMock(ContainerInterface::class),
            $router,
            $climate,
        );

        $app->run(['bin/console', 'help', 'users:create']);

        $out = $buffer->get();
        self::assertStringContainsString('USAGE', $out);
        self::assertStringContainsString('ARGUMENTS', $out);
        self::assertStringContainsString('role', $out);
        self::assertStringContainsString('OPTIONS', $out);
        self::assertStringContainsString('--email=<value>', $out);
        self::assertStringContainsString('--dry-run', $out);
    }

    #[Test]
    public function helpForNamespaceListsOnlyMatchingCommands(): void
    {
        [$climate, $buffer] = self::bufferedClimate();

        $router = new ConsoleRouter([
            new ConsoleDefinition('mongo:ensure-indexes', 'A', 'Ensure indexes'),
            new ConsoleDefinition('users:create {role}', 'B', 'Create user'),
        ]);

        $app = new ConsoleApplication(
            $this->createMock(ContainerInterface::class),
            $router,
            $climate,
        );

        $app->run(['bin/console', 'help', 'mongo']);

        $out = $buffer->get();
        self::assertStringContainsString('mongo:ensure-indexes', $out);
        self::assertStringNotContainsString('users:create', $out);
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

        [$climate] = self::bufferedClimate();
        $app = new ConsoleApplication($container, $router, $climate);

        $exit = $app->run(['bin/console', 'users:create', '--dry-run', 'admin']);

        self::assertSame(0, $exit);
        self::assertInstanceOf(ConsoleInput::class, $captured);
        self::assertSame(['role' => 'admin'], $captured->pathParams);
        self::assertTrue($captured->options['dry-run']);
    }

    #[Test]
    public function handlerReturnValueBecomesExitCode(): void
    {
        $exit = $this->runHandler(static fn(ConsoleInput $in, ConsoleOutput $out): int => 1);

        self::assertSame(1, $exit);
    }

    #[Test]
    public function setExitCodeAppliesWhenHandlerReturnsNothingOrZero(): void
    {
        self::assertSame(2, $this->runHandler(static function (ConsoleInput $in, ConsoleOutput $out): void {
            $out->setExitCode(2);
        }));
        self::assertSame(3, $this->runHandler(static function (ConsoleInput $in, ConsoleOutput $out): int {
            $out->setExitCode(3);
            return 0;
        }));
        self::assertSame(0, $this->runHandler(static fn(ConsoleInput $in, ConsoleOutput $out): int => 0));
    }

    private function runHandler(\Closure $handler): int
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with('Handler')->willReturn($handler);

        [$climate] = self::bufferedClimate();
        $app = new ConsoleApplication(
            $container,
            new ConsoleRouter([new ConsoleDefinition('job:run', 'Handler', 'Run job')]),
            $climate,
        );

        return $app->run(['bin/console', 'job:run']);
    }

    /** @return array{0: CLImate, 1: Buffer, 2: Buffer} */
    private static function bufferedClimate(): array
    {
        $climate = new CLImate();
        $out     = new Buffer();
        $err     = new Buffer();
        $climate->output->add('out',   $out);
        $climate->output->add('error', $err);
        // re-bind defaults since the constructor captured the original StdOut/StdErr instances
        $climate->output->defaultTo('out');
        return [$climate, $out, $err];
    }
}
