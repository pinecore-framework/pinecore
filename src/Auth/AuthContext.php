<?php

namespace Pinecore\Pinecore\Auth;

final class AuthContext
{
    public function __construct(
        public readonly object $user,
    ) {}
}
