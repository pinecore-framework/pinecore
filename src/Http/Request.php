<?php

namespace Pronchev\Pinecore\Http;

final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $pathParams
     * @param array<string, string> $headers  lowercase-hyphen keys (e.g. 'content-type', 'authorization')
     * @param array<array-key, mixed> $body   parsed body (JSON object/list, form fields, or [])
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

    /** @return array<array-key, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
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
        array $get, array $post, array $cookies, array $files, array $server, string $rawBody
    ): self {
        $method  = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $uri     = $server['REQUEST_URI'] ?? '/';
        $path    = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = self::extractHeaders($server);

        $body = self::parseBody($method, $headers['content-type'] ?? '', $post, $rawBody);

        return new self($method, $path, $get, [], $headers, $body, $cookies, $files);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<array-key, mixed>
     */
    private static function parseBody(string $method, string $contentType, array $post, string $rawBody): array
    {
        if ($contentType === '') {
            return [];
        }

        if (str_contains($contentType, 'application/json')) {
            if ($rawBody === '') {
                return [];
            }
            try {
                $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpException('Invalid JSON body', 400);
            }
            if (!is_array($decoded)) {
                throw new HttpException('JSON body must be an object or array', 400);
            }
            return $decoded;
        }

        if (str_contains($contentType, 'multipart/form-data')) {
            return $post;
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            if ($method === 'POST') {
                return $post;
            }
            if ($rawBody === '') {
                return [];
            }
            parse_str($rawBody, $parsed);
            return $parsed;
        }

        return [];
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
        if (isset($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
        }
        return $headers;
    }
}
