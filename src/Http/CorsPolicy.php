<?php

namespace Pronchev\Pinecore\Http;

use Pronchev\Pinecore\Config;

final class CorsPolicy
{
    /** @var list<string> */
    private readonly array $allowedOrigins;
    /** @var list<string> */
    private readonly array $allowedMethods;
    /** @var list<string> */
    private readonly array $allowedHeaders;
    private readonly bool $allowCredentials;
    private readonly int $maxAge;
    private readonly bool $isWildcardOnly;

    public function __construct(Config $config)
    {
        $cors = $config->get('app.cors');
        if (!is_array($cors)) {
            throw new \RuntimeException('app.cors config is missing');
        }

        $this->allowedOrigins   = self::asStringList($cors, 'allowed_origins');
        $this->allowedMethods   = self::asStringList($cors, 'allowed_methods');
        $this->allowedHeaders   = self::asStringList($cors, 'allowed_headers');
        $this->allowCredentials = (bool) ($cors['allow_credentials'] ?? false);
        $this->maxAge           = (int)  ($cors['max_age'] ?? 600);

        $this->isWildcardOnly = $this->allowedOrigins === ['*'];

        if ($this->isWildcardOnly && $this->allowCredentials) {
            // Per Fetch Standard: Access-Control-Allow-Origin: * is incompatible
            // with Access-Control-Allow-Credentials: true. Browsers reject the
            // combination. Fail at boot, not silently in prod.
            throw new \RuntimeException(
                'app.cors: allowed_origins=["*"] cannot be combined with allow_credentials=true. '
                . 'List explicit origins instead.',
            );
        }
    }

    /**
     * Headers attached to a normal (non-OPTIONS) response.
     *
     * @return array<string, string>
     */
    public function simpleHeaders(?string $requestOrigin): array
    {
        $allowOrigin = $this->resolveOrigin($requestOrigin);

        $headers = [];
        if ($allowOrigin !== null) {
            $headers['Access-Control-Allow-Origin'] = $allowOrigin;
            if ($this->allowCredentials) {
                $headers['Access-Control-Allow-Credentials'] = 'true';
            }
        }
        if (!$this->isWildcardOnly) {
            $headers['Vary'] = 'Origin';
        }
        return $headers;
    }

    /**
     * Headers for an OPTIONS preflight response. When the origin is rejected,
     * returns an empty array (or just Vary) — the browser treats the missing
     * Allow-Origin as a default-deny.
     *
     * @return array<string, string>
     */
    public function preflightHeaders(?string $requestOrigin): array
    {
        $allowOrigin = $this->resolveOrigin($requestOrigin);

        $headers = [];
        if ($allowOrigin !== null) {
            $headers['Access-Control-Allow-Origin']  = $allowOrigin;
            $headers['Access-Control-Allow-Methods'] = implode(', ', $this->allowedMethods);
            $headers['Access-Control-Allow-Headers'] = implode(', ', $this->allowedHeaders);
            $headers['Access-Control-Max-Age']       = (string) $this->maxAge;
            if ($this->allowCredentials) {
                $headers['Access-Control-Allow-Credentials'] = 'true';
            }
        }
        if (!$this->isWildcardOnly) {
            $headers['Vary'] = 'Origin';
        }
        return $headers;
    }

    private function resolveOrigin(?string $requestOrigin): ?string
    {
        if ($this->isWildcardOnly) {
            // Already validated incompatibility with credentials in the ctor.
            return '*';
        }
        if ($requestOrigin === null || $requestOrigin === '') {
            return null;
        }
        return in_array($requestOrigin, $this->allowedOrigins, true) ? $requestOrigin : null;
    }

    /**
     * @return list<string>
     */
    private static function asStringList(array $cors, string $key): array
    {
        $value = $cors[$key] ?? [];
        if (!is_array($value)) {
            throw new \RuntimeException("app.cors.{$key} must be a list of strings");
        }
        return array_values(array_map('strval', $value));
    }
}
