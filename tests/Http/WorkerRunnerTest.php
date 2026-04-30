<?php

namespace Tests\Pronchev\Pinecore\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pronchev\Pinecore\ExceptionHandler;
use Pronchev\Pinecore\Http\HttpApplication;
use Pronchev\Pinecore\Http\WorkerRunner;

final class WorkerRunnerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    #[Test]
    public function singleRequestModeInvokesHandleAndTerminateOnce(): void
    {
        $app = $this->createMock(HttpApplication::class);
        $app->expects(self::once())->method('handleRequest');
        $app->expects(self::once())->method('terminate');

        $runner = new WorkerRunner($app, $this->createMock(ExceptionHandler::class));
        $runner->run();
    }

    #[Test]
    public function loopExitsWhenAdapterReturnsFalse(): void
    {
        unset($_SERVER['MAX_REQUESTS']);

        $app = $this->createMock(HttpApplication::class);
        $app->expects(self::once())->method('handleRequest');
        $app->expects(self::once())->method('terminate');

        $runner = new WorkerRunner($app, $this->createMock(ExceptionHandler::class));

        $runner->run(function ($handler) {
            $handler();
            return false;
        });
    }

    #[Test]
    public function maxRequestsLimitsLoopIterations(): void
    {
        $_SERVER['MAX_REQUESTS'] = 3;

        $app = $this->createMock(HttpApplication::class);
        $app->expects(self::exactly(3))->method('handleRequest');
        $app->expects(self::exactly(3))->method('terminate');

        $runner = new WorkerRunner($app, $this->createMock(ExceptionHandler::class));

        $iterations = 0;
        $runner->run(function ($handler) use (&$iterations) {
            $iterations++;
            $handler();
            return true;     // adapter would loop forever, MAX_REQUESTS must stop it
        });

        self::assertSame(3, $iterations);
    }

    #[Test]
    public function requestShutdownExitsLoopEvenIfAdapterWantsToContinue(): void
    {
        unset($_SERVER['MAX_REQUESTS']);

        $app = $this->createMock(HttpApplication::class);
        $app->expects(self::exactly(2))->method('handleRequest');
        $app->expects(self::exactly(2))->method('terminate');

        $runner = new WorkerRunner($app, $this->createMock(ExceptionHandler::class));

        $iterations = 0;
        $runner->run(function ($handler) use (&$iterations, $runner) {
            $iterations++;
            $handler();
            if ($iterations === 2) {
                $runner->requestShutdown();   // simulates SIGTERM during 2nd request
            }
            return true;                       // adapter still wants more
        });

        self::assertSame(2, $iterations);
    }
}
