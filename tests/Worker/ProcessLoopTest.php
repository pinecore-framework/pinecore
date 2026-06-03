<?php

namespace Tests\Pinecore\Pinecore\Worker;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Worker\ProcessLoop;
use Pinecore\Pinecore\WorkerLifecycle;

final class ProcessLoopTest extends TestCase
{
    #[Test]
    public function maxRequestsBoundsIterations(): void
    {
        $loop = new ProcessLoop(new WorkerLifecycle(), maxRequests: 3);

        $ticks = 0;
        $loop->run(function () use (&$ticks): bool {
            $ticks++;
            return true;   // never asks to stop; maxRequests must
        });

        self::assertSame(3, $ticks);
    }

    #[Test]
    public function unlimitedRunsUntilTickReturnsFalse(): void
    {
        $loop = new ProcessLoop(new WorkerLifecycle(), maxRequests: 0);

        $ticks = 0;
        $loop->run(function () use (&$ticks): bool {
            $ticks++;
            return $ticks < 5;
        });

        self::assertSame(5, $ticks);
    }

    #[Test]
    public function shutdownFlagStopsLoopAfterCurrentTick(): void
    {
        $lifecycle = new WorkerLifecycle();
        $loop      = new ProcessLoop($lifecycle, maxRequests: 0);

        $ticks = 0;
        $loop->run(function () use (&$ticks, $lifecycle): bool {
            $ticks++;
            if ($ticks === 2) {
                $lifecycle->requestShutdown();   // e.g. SIGTERM or lost DB connection
            }
            return true;                          // tick still wants to continue
        });

        self::assertSame(2, $ticks);
    }

    #[Test]
    public function requestShutdownDelegatesToLifecycle(): void
    {
        $lifecycle = new WorkerLifecycle();
        $loop      = new ProcessLoop($lifecycle);

        $loop->requestShutdown();

        self::assertTrue($lifecycle->shouldShutdown());
    }
}
