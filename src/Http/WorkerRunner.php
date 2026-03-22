<?php

namespace Pronchev\Pinecore\Http;

use Pronchev\Pinecore\ExceptionHandler;

final class WorkerRunner
{
    public function __construct(
        private readonly HttpApplication  $app,
        private readonly ExceptionHandler $exceptionHandler,
    ) {}

    public function run(): void
    {
        $maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 0);

        for ($n = 0; !$maxRequests || $n < $maxRequests; ++$n) {
            $keepRunning = frankenphp_handle_request(function (): void {
                try {
                    $this->app->handleRequest($_GET, $_POST, $_COOKIE, $_FILES, $_SERVER);
                } catch (\Throwable $e) {
                    $this->exceptionHandler->handleException($e);
                }
            });

            $this->app->terminate();
            gc_collect_cycles();

            if (!$keepRunning) {
                break;
            }
        }
    }
}
