<?php

namespace Pronchev\Pinecore\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int    $status  = 200,
        public readonly array  $headers = [],
        public readonly string $body    = '',
    ) {}

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            status:  $status,
            headers: ['Content-Type' => 'application/json'],
            body:    json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    public static function error(string $message, int $status): self
    {
        return self::json(['error' => $message], $status);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self(
            $this->status,
            array_merge($this->headers, [$name => $value]),
            $this->body,
        );
    }

    public function withHeaders(array $headers): self
    {
        return new self($this->status, array_merge($this->headers, $headers), $this->body);
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
