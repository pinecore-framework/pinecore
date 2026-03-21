<?php

namespace Pronchev\Pinecore\Auth;

final class AuthContext
{
    public function __construct(
        public readonly object $user,
    ) {}
}
