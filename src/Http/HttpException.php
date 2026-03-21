<?php

namespace Pronchev\Pinecore\Http;

final class HttpException extends \RuntimeException
{
    public function __construct(string $message, int $statusCode)
    {
        parent::__construct($message, $statusCode);
    }
}
