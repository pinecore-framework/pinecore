<?php

namespace Tests\Pinecore\Pinecore\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\ExceptionHandler;
use Pinecore\Pinecore\Http\HttpApplication;
use Pinecore\Pinecore\Http\WorkerRunner;

final class WorkerRunnerTest extends TestCase
{
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
        $app = $this->createMock(HttpApplication::class);
        $app->expects(self::once())->method('handleRequest');
        $app->expects(self::once())->method('terminate');

        $runner = new WorkerRunner($app, $this->createMock(ExceptionHandler::class));

        $runner->run(function ($handler) {
            $handler([], [], [], [], [], '');
            return false;
        });
    }

    #[Test]
    public function maxRequestsLimitsLoopIterations(): void
    {
        $app = $this->createMock(HttpApplication::class);
        $app->expects(self::exactly(3))->method('handleRequest');
        $app->expects(self::exactly(3))->method('terminate');

        $runner = new WorkerRunner(
            $app,
            $this->createMock(ExceptionHandler::class),
            maxRequests: 3,
        );

        $iterations = 0;
        $runner->run(function ($handler) use (&$iterations) {
            $iterations++;
            $handler([], [], [], [], [], '');
            return true;     // adapter would loop forever, maxRequests must stop it
        });

        self::assertSame(3, $iterations);
    }

    #[Test]
    public function maxRequestsZeroMeansUnlimited(): void
    {
        $app = $this->createMock(HttpApplication::class);

        $runner = new WorkerRunner(
            $app,
            $this->createMock(ExceptionHandler::class),
            maxRequests: 0,
        );

        $iterations = 0;
        $runner->run(function ($handler) use (&$iterations) {
            $iterations++;
            $handler([], [], [], [], [], '');
            // Adapter, not maxRequests, decides when to stop.
            return $iterations < 5;
        });

        self::assertSame(5, $iterations);
    }

    #[Test]
    public function requestShutdownExitsLoopEvenIfAdapterWantsToContinue(): void
    {
        $app = $this->createMock(HttpApplication::class);
        $app->expects(self::exactly(2))->method('handleRequest');
        $app->expects(self::exactly(2))->method('terminate');

        $runner = new WorkerRunner($app, $this->createMock(ExceptionHandler::class));

        $iterations = 0;
        $runner->run(function ($handler) use (&$iterations, $runner) {
            $iterations++;
            $handler([], [], [], [], [], '');
            if ($iterations === 2) {
                $runner->requestShutdown();   // simulates SIGTERM during 2nd request
            }
            return true;                       // adapter still wants more
        });

        self::assertSame(2, $iterations);
    }

    #[Test]
    public function adapterDataReachesHandleRequestVerbatim(): void
    {
        $app = $this->createMock(HttpApplication::class);

        $get    = ['q' => 'search'];
        $post   = ['name' => 'alice'];
        $cookie = ['sid' => 'abc'];
        $files  = ['upload' => ['name' => 'x.txt']];
        $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/echo'];
        $body   = '{"k":"v"}';

        $app->expects(self::once())
            ->method('handleRequest')
            ->with($get, $post, $cookie, $files, $server, $body);

        $runner = new WorkerRunner($app, $this->createMock(ExceptionHandler::class));

        $runner->run(function ($handler) use ($get, $post, $cookie, $files, $server, $body) {
            $handler($get, $post, $cookie, $files, $server, $body);
            return false;
        });
    }
}
