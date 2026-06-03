<?php

namespace Pinecore\Pinecore\Http;

use Pinecore\Pinecore\ExceptionHandler;
use Pinecore\Pinecore\WorkerLifecycle;

final class WorkerRunner
{
    private readonly WorkerLifecycle $lifecycle;

    public function __construct(
        private readonly HttpApplication  $app,
        private readonly ExceptionHandler $exceptionHandler,
        private readonly int              $maxRequests = 0,
        ?WorkerLifecycle                  $lifecycle = null,
    ) {
        $this->lifecycle = $lifecycle ?? new WorkerLifecycle();
    }

    public function run(?callable $loop = null): void
    {
        if ($loop === null) {
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

        $this->installSignalHandlers();

        // Worker-loop mode: the adapter sources per-iteration request data
        // from its runtime (RoadRunner, FrankenPHP, ReactPHP, ...) and feeds
        // it to the handler. WorkerRunner does not touch superglobals here —
        // they are not repopulated between iterations in worker runtimes.
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

        for ($n = 0; !$this->maxRequests || $n < $this->maxRequests; ++$n) {
            $keepRunning = $loop($handler);

            $this->app->terminate();
            gc_collect_cycles();

            if (!$keepRunning || $this->lifecycle->shouldShutdown()) {
                break;
            }
        }
    }

    /**
     * Programmatically request the worker loop to exit after the current
     * iteration completes. Also called from SIGTERM/SIGINT handlers.
     */
    public function requestShutdown(): void
    {
        $this->lifecycle->requestShutdown();
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->requestShutdown());
        pcntl_signal(SIGINT,  fn () => $this->requestShutdown());
    }
}
