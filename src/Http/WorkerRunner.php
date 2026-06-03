<?php

namespace Pinecore\Pinecore\Http;

use Pinecore\Pinecore\ExceptionHandler;
use Pinecore\Pinecore\Worker\ProcessLoop;

/**
 * HTTP consumer of ProcessLoop. Adapts the runtime's per-request data to
 * HttpApplication::handleRequest; the loop skeleton (signals, maxRequests, GC,
 * graceful shutdown, DB-connection recycle) lives in ProcessLoop.
 */
final class WorkerRunner
{
    public function __construct(
        private readonly HttpApplication  $app,
        private readonly ExceptionHandler $exceptionHandler,
        private readonly ProcessLoop      $loop,
    ) {}

    public function run(?callable $adapter = null): void
    {
        if ($adapter === null) {
            // SAPI/CGI/FPM single-request mode: PHP runtime populates the
            // superglobals and php://input for us.
            try {
                $this->app->handleRequest($_GET, $_POST, $_COOKIE, $_FILES, $_SERVER);
            } catch (\Throwable $e) {
                $this->exceptionHandler->handleException($e);
            }
            $this->app->terminate();
            return;
        }

        // Worker-loop mode: the adapter sources per-iteration request data from
        // its runtime (RoadRunner, FrankenPHP, ReactPHP, ...) and feeds it to the
        // handler. Superglobals are not touched — worker runtimes do not
        // repopulate them between iterations.
        $handler = function (
            array $get,
            array $post,
            array $cookie,
            array $files,
            array $server,
            string $rawBody,
        ): void {
            try {
                $this->app->handleRequest($get, $post, $cookie, $files, $server, $rawBody);
            } catch (\Throwable $e) {
                $this->exceptionHandler->handleException($e);
            }
        };

        $this->loop->run(function () use ($adapter, $handler): bool {
            $keepRunning = $adapter($handler);
            $this->app->terminate();
            return (bool) $keepRunning;
        });
    }

    /**
     * Programmatically request the worker loop to exit after the current
     * iteration. Delegates to the shared ProcessLoop / WorkerLifecycle.
     */
    public function requestShutdown(): void
    {
        $this->loop->requestShutdown();
    }
}
