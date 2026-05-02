<?php

namespace Tests\Pronchev\Pinecore;

use DI\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pronchev\Pinecore\Config;
use Pronchev\Pinecore\Environment;
use Pronchev\Pinecore\Kernel;
use Psr\Log\LoggerInterface;

final class KernelTest extends TestCase
{
    #[Test]
    public function bootReturnsAContainer(): void
    {
        $container = Kernel::boot(self::tempBasePath());

        self::assertInstanceOf(Container::class, $container);
    }

    #[Test]
    public function frameworkServicesAreResolvable(): void
    {
        $container = Kernel::boot(self::tempBasePath());

        self::assertInstanceOf(Config::class, $container->get(Config::class));
        self::assertInstanceOf(Environment::class, $container->get(Environment::class));
        self::assertInstanceOf(LoggerInterface::class, $container->get(LoggerInterface::class));
    }

    #[Test]
    public function consecutiveBootsReturnDistinctContainers(): void
    {
        // Fixes that Kernel does not retain a static container between calls.
        $a = Kernel::boot(self::tempBasePath());
        $b = Kernel::boot(self::tempBasePath());

        self::assertNotSame($a, $b);
    }

    /**
     * Returns a path with no config/ subdir — Config::load tolerates missing
     * directories, so Kernel can boot against an effectively empty app.
     */
    private static function tempBasePath(): string
    {
        return sys_get_temp_dir();
    }
}
