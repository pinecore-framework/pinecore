<?php

namespace Pinecore\Pinecore\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class CompositeLogger extends AbstractLogger
{
    /** @param LoggerInterface[] $loggers */
    public function __construct(private readonly array $loggers) {}

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        foreach ($this->loggers as $logger) {
            $logger->log($level, $message, $context);
        }
    }
}
