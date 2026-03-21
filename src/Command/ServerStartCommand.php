<?php

namespace Pronchev\Pinecore\Command;

use Pronchev\Pinecore\ExceptionHandler;
use Pronchev\Pinecore\Http\HttpApplication;
use Pronchev\Pinecore\Console\ConsoleInput;
use Pronchev\Pinecore\Console\ConsoleOutput;

final class ServerStartCommand
{
    public function __construct(
        private readonly HttpApplication  $app,
        private readonly ExceptionHandler $exceptionHandler,
    ) {}

    public function __invoke(ConsoleInput $input, ConsoleOutput $output): int
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

        return 0;
    }
}
