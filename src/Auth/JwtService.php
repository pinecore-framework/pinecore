<?php

namespace Pronchev\Pinecore\Auth;

use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Pronchev\Pinecore\Config;
use Psr\Clock\ClockInterface;

final class JwtService
{
    private readonly Configuration $jwtConfig;
    private readonly int $accessTtl;

    public function __construct(Config $config)
    {
        $secret = $config->get('jwt.secret');
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new \RuntimeException(
                'jwt.secret must be a string of at least 32 bytes (HS256 requirement)'
            );
        }

        $accessTtl = $config->get('jwt.access_ttl');
        if (!is_int($accessTtl) || $accessTtl <= 0) {
            throw new \RuntimeException('jwt.access_ttl must be a positive integer');
        }
        $this->accessTtl = $accessTtl;

        $signer = new Sha256();
        $key    = InMemory::plainText($secret);

        $jwtConfig = Configuration::forSymmetricSigner($signer, $key);
        $this->jwtConfig = $jwtConfig->withValidationConstraints(
            new SignedWith($signer, $key),
            new StrictValidAt(new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable();
                }
            }),
        );
    }

    public function issue(string $userId): string
    {
        $now   = new DateTimeImmutable();
        $token = $this->jwtConfig->builder()
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify("+{$this->accessTtl} seconds"))
            ->relatedTo($userId)
            ->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return $token->toString();
    }

    public function verify(string $tokenString): string
    {
        try {
            $token = $this->jwtConfig->parser()->parse($tokenString);
        } catch (\Throwable) {
            throw new AuthException('Invalid token');
        }

        if (!$this->jwtConfig->validator()->validate($token, ...$this->jwtConfig->validationConstraints())) {
            throw new AuthException('Token validation failed');
        }

        return $token->claims()->get('sub');
    }
}
