<?php

namespace Pronchev\Pinecore\Auth;

interface UserProviderInterface
{
    public function findById(string $id): ?object;
}
