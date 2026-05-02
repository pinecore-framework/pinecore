<?php

namespace Pinecore\Pinecore;

use Psr\Log\LoggerInterface;

class ExceptionHandler
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handleException(\Throwable $exception): void
    {
        $this->logger->critical('Unhandled exception in worker loop', [
            'exception' => $exception,
        ]);
    }
}
