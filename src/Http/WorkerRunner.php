<?php

namespace Pronchev\Pinecore\Http;

use Pronchev\Pinecore\ExceptionHandler;

final class WorkerRunner
{
    private bool $shouldShutdown = false;

    public function __construct(
        private readonly HttpApplication  $app,
        private readonly ExceptionHandler $exceptionHandler,
    ) {}

    public function run(?callable $loop = null): void
    {
        if ($loop === null) {
            $this->handle();
            $this->app->terminate();
            return;
        }

        $this->installSignalHandlers();

        $handler     = fn () => $this->handle();
        $maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);

        for ($n = 0; !$maxRequests || $n < $maxRequests; ++$n) {
            $keepRunning = $loop($handler);

            $this->app->terminate();
            gc_collect_cycles();

            if (!$keepRunning || $this->shouldShutdown) {
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
        $this->shouldShutdown = true;
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

    private function handle(): void
    {
        try {
            $this->app->handleRequest($_GET, $_POST, $_COOKIE, $_FILES, $_SERVER);
        } catch (\Throwable $e) {
            $this->exceptionHandler->handleException($e);
        }
    }
}
