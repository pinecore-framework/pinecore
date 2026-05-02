<?php

namespace Pinecore\Pinecore\Log;

use Psr\Log\AbstractLogger;

final class NullLogger extends AbstractLogger
{
    public function log(mixed $level, string|\Stringable $message, array $context = []): void {}
}
