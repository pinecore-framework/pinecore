<?php

namespace Pinecore\Pinecore\Orm;

final class IndexMetadata
{
    public function __construct(
        public readonly string  $field,
        public readonly int     $direction,
        public readonly bool    $unique,
        public readonly bool    $sparse,
        public readonly ?string $name,
        public readonly ?int    $expireAfterSeconds,
        /** SQL index method ('gist', 'gin', …); null = engine default. Mongo ignores it. */
        public readonly ?string $using = null,
    ) {}
}
