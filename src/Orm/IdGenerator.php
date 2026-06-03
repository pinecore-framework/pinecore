<?php

namespace Pinecore\Pinecore\Orm;

final class IdGenerator implements IdGeneratorInterface
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
