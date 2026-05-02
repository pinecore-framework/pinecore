<?php

namespace Pinecore\Pinecore\Auth;

use Pinecore\Pinecore\Http\MiddlewareInterface;
use Pinecore\Pinecore\Http\Request;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly JwtService              $jwt,
        private readonly UserProviderInterface   $users,
    ) {}

    /**
     * Resolves the JWT from the Authorization header and injects AuthContext into the request.
     *
     * @throws AuthException if the token is missing, invalid, or the user does not exist
     */
    public function process(Request $request): Request
    {
        $header = $request->headers['authorization'] ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            throw new AuthException('Missing or malformed Authorization header');
        }

        $tokenString = substr($header, 7);
        $userId      = $this->jwt->verify($tokenString);
        $user        = $this->users->findById($userId);

        if ($user === null) {
            throw new AuthException('User not found');
        }

        return $request->withContext('auth', new AuthContext($user));
    }
}
