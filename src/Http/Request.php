<?php

namespace Pronchev\Pinecore\Http;

final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $pathParams
     * @param array<string, string> $headers  lowercase-hyphen keys (e.g. 'content-type', 'authorization')
     * @param array<string, mixed>  $body     decoded JSON body
     * @param array<string, string> $cookies  $_COOKIE
     * @param array<string, mixed>  $files    $_FILES
     * @param array<string, mixed>  $context  middleware-injected values (e.g. AuthContext)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array  $query,
        public readonly array  $pathParams,
        public readonly array  $headers,
        private readonly array $body,
        public readonly array  $cookies,
        public readonly array  $files,
        private array          $context = [],
    ) {}

    public function body(): array
    {
        return $this->body;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    public function withContext(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->context[$key] = $value;
        return $clone;
    }

    public function withPathParams(array $params): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $params,
            $this->headers,
            $this->body,
            $this->cookies,
            $this->files,
            $this->context,
        );
    }

    public static function fromGlobals(
        array $get, array $cookies, array $files, array $server, string $rawBody
    ): self {
        $method  = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $uri     = $server['REQUEST_URI'] ?? '/';
        $path    = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = self::extractHeaders($server);

        $body = [];
        $contentType = $headers['content-type'] ?? '';
        if (str_contains($contentType, 'application/json') && $rawBody !== '') {
            $body = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        }

        return new self($method, $path, $get, [], $headers, $body, $cookies, $files);
    }

    /** @return array<string, string> */
    private static function extractHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name           = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        // CONTENT_TYPE and CONTENT_LENGTH have no HTTP_ prefix in $_SERVER
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $server['CONTENT_TYPE'];
        }
        return $headers;
    }
}
