<?php

namespace Tests\Pinecore\Pinecore\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Console\ConsoleApplication;
use Pinecore\Pinecore\Console\ConsoleRouter;
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
}
