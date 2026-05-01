<?php

namespace Tests\Pronchev\Pinecore\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pronchev\Pinecore\Auth\AuthException;
use Pronchev\Pinecore\Auth\JwtService;
use Pronchev\Pinecore\Config;

final class JwtServiceTest extends TestCase
{
    private const VALID_SECRET = '0123456789abcdef0123456789abcdef'; // 32 bytes

    #[Test]
    public function missingSecretIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('jwt.secret');

        new JwtService(self::config(secret: null));
    }

    #[Test]
    public function emptySecretIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('jwt.secret');

        new JwtService(self::config(secret: ''));
    }

    #[Test]
    public function shortSecretIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('32 bytes');

        new JwtService(self::config(secret: str_repeat('a', 31)));
    }

    #[Test]
    public function nonStringSecretIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('jwt.secret');

        new JwtService(self::config(secret: 12345));
    }

    #[Test]
    public function exact32ByteSecretIsAccepted(): void
    {
        $service = new JwtService(self::config(secret: str_repeat('a', 32)));

        $token = $service->issue('user-1');
        self::assertSame('user-1', $service->verify($token));
    }

    #[Test]
    public function missingAccessTtlIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('jwt.access_ttl');

        new JwtService(self::config(accessTtl: null));
    }

    #[Test]
    public function nonIntAccessTtlIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('jwt.access_ttl');

        new JwtService(self::config(accessTtl: '3600'));
    }

    #[Test]
    public function zeroAccessTtlIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('positive integer');

        new JwtService(self::config(accessTtl: 0));
    }

    #[Test]
    public function negativeAccessTtlIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('positive integer');

        new JwtService(self::config(accessTtl: -10));
    }

    #[Test]
    public function issueAndVerifyRoundtripReturnsSubject(): void
    {
        $service = new JwtService(self::config());

        $token   = $service->issue('user-42');
        $subject = $service->verify($token);

        self::assertSame('user-42', $subject);
    }

    #[Test]
    public function tamperedTokenIsRejected(): void
    {
        $service = new JwtService(self::config());
        $token   = $service->issue('user-1');

        // Flip last char of the signature segment.
        $tampered = substr($token, 0, -1) . (substr($token, -1) === 'A' ? 'B' : 'A');

        $this->expectException(AuthException::class);
        $service->verify($tampered);
    }

    #[Test]
    public function tokenSignedWithDifferentSecretIsRejected(): void
    {
        $issuer   = new JwtService(self::config(secret: str_repeat('x', 32)));
        $verifier = new JwtService(self::config(secret: str_repeat('y', 32)));

        $token = $issuer->issue('user-1');

        $this->expectException(AuthException::class);
        $verifier->verify($token);
    }

    #[Test]
    public function malformedTokenIsRejected(): void
    {
        $service = new JwtService(self::config());

        $this->expectException(AuthException::class);
        $service->verify('not-a-jwt');
    }

    private static function config(
        mixed $secret = self::VALID_SECRET,
        mixed $accessTtl = 3600,
    ): Config {
        return new Config([
            'jwt' => [
                'secret'     => $secret,
                'access_ttl' => $accessTtl,
            ],
        ]);
    }
}
