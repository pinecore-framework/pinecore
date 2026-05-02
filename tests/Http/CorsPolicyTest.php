<?php

namespace Tests\Pinecore\Pinecore\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pinecore\Pinecore\Config;
use Pinecore\Pinecore\Http\CorsPolicy;

final class CorsPolicyTest extends TestCase
{
    #[Test]
    public function wildcardWithoutCredentialsReturnsStarAndOmitsVary(): void
    {
        $policy = self::policy(['*']);

        $headers = $policy->simpleHeaders('https://anything.example.com');

        self::assertSame('*', $headers['Access-Control-Allow-Origin']);
        self::assertArrayNotHasKey('Vary', $headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
    }

    #[Test]
    public function wildcardWithCredentialsThrowsAtConstruction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be combined with allow_credentials');

        self::policy(['*'], allowCredentials: true);
    }

    #[Test]
    public function allowlistMatchEchoesOriginAndAddsVary(): void
    {
        $policy = self::policy(['https://app.example.com', 'https://staging.example.com']);

        $headers = $policy->simpleHeaders('https://app.example.com');

        self::assertSame('https://app.example.com', $headers['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $headers['Vary']);
    }

    #[Test]
    public function allowlistMissOmitsAllowOrigin(): void
    {
        $policy = self::policy(['https://app.example.com']);

        $headers = $policy->simpleHeaders('https://evil.example.org');

        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
        // Vary still emitted — caches must not key on origin.
        self::assertSame('Origin', $headers['Vary']);
    }

    #[Test]
    public function noOriginHeaderProducesNoAllowOrigin(): void
    {
        $policy = self::policy(['https://app.example.com']);

        $headers = $policy->simpleHeaders(null);

        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame('Origin', $headers['Vary']);
    }

    #[Test]
    public function credentialsHeaderEmittedOnlyWhenOriginAllowedAndConfigEnabled(): void
    {
        $policy = self::policy(['https://app.example.com'], allowCredentials: true);

        $allowed  = $policy->simpleHeaders('https://app.example.com');
        $rejected = $policy->simpleHeaders('https://evil.example.org');

        self::assertSame('true', $allowed['Access-Control-Allow-Credentials']);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $rejected);
    }

    #[Test]
    public function preflightForAllowedOriginIncludesMethodsHeadersAndMaxAge(): void
    {
        $policy = self::policy(['https://app.example.com']);

        $headers = $policy->preflightHeaders('https://app.example.com');

        self::assertSame('https://app.example.com', $headers['Access-Control-Allow-Origin']);
        self::assertSame('GET, POST, OPTIONS', $headers['Access-Control-Allow-Methods']);
        self::assertSame('Content-Type, Authorization', $headers['Access-Control-Allow-Headers']);
        self::assertSame('600', $headers['Access-Control-Max-Age']);
    }

    #[Test]
    public function preflightForRejectedOriginOmitsMethodsAndHeaders(): void
    {
        $policy = self::policy(['https://app.example.com']);

        $headers = $policy->preflightHeaders('https://evil.example.org');

        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Headers', $headers);
        self::assertArrayNotHasKey('Access-Control-Max-Age', $headers);
    }

    #[Test]
    public function simpleHeadersDoNotIncludeMethodsOrHeadersList(): void
    {
        // Allow-Methods / Allow-Headers are preflight-only by spec.
        $policy = self::policy(['https://app.example.com']);

        $headers = $policy->simpleHeaders('https://app.example.com');

        self::assertArrayNotHasKey('Access-Control-Allow-Methods', $headers);
        self::assertArrayNotHasKey('Access-Control-Allow-Headers', $headers);
        self::assertArrayNotHasKey('Access-Control-Max-Age', $headers);
    }

    #[Test]
    public function missingCorsConfigSectionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing required key "app.cors"');

        new CorsPolicy(new Config([]));
    }

    /**
     * @param list<string> $allowedOrigins
     */
    private static function policy(array $allowedOrigins, bool $allowCredentials = false): CorsPolicy
    {
        return new CorsPolicy(new Config([
            'app' => ['cors' => [
                'allowed_origins'   => $allowedOrigins,
                'allowed_methods'   => ['GET', 'POST', 'OPTIONS'],
                'allowed_headers'   => ['Content-Type', 'Authorization'],
                'allow_credentials' => $allowCredentials,
                'max_age'           => 600,
            ]],
        ]));
    }
}
