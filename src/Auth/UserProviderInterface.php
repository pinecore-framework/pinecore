<?php

namespace Pinecore\Pinecore\Auth;

interface UserProviderInterface
{
    public function findById(string $id): ?object;
}
